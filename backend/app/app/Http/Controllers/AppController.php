<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppController extends Controller
{
    public function package(Request $request, $appId)
    {
        if (!ctype_digit((string) $appId)) {
            return response()->json(['error' => 'Invalid appId (must be numeric).'], 400);
        }
        $appId = (int) $appId;

        $row = DB::table('learning_apps')->where('id', $appId)->first();

        if (!$row) {
            // Create a simple dummy app the first time it's requested.
            DB::table('learning_apps')->insert([
                'id' => $appId,
                'title' => 'Counter with resume',
                'html' => "<div id='app'></div>",
                'css' => "body{font-family:system-ui;padding:16px} button{font-size:16px;padding:8px 12px;margin-right:8px}",
                'js' => $this->defaultJs(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table('learning_apps')->where('id', $appId)->first();
        }

        return response()->json([
            'id' => $row->id,
            'title' => $row->title,
            'html' => $row->html,
            'css' => $row->css,
            'js' => $row->js,
        ]);
    }

    public function getState(Request $request, $appId)
    {
        if (!ctype_digit((string) $appId)) {
            return response()->json(['error' => 'Invalid appId (must be numeric).'], 400);
        }
        $appId = (int) $appId;

        $sub = $request->attributes->get('auth_sub');

        $row = DB::table('app_states')
            ->where('app_id', $appId)
            ->where('user_sub', $sub)
            ->first();

        if (!$row) {
            return response()->json(['state' => null]);
        }

        $state = json_decode($row->state_json, true);
        return response()->json(['state' => $state]);
    }

    public function setState(Request $request, $appId)
    {
        if (!ctype_digit((string) $appId)) {
            return response()->json(['error' => 'Invalid appId (must be numeric).'], 400);
        }
        $appId = (int) $appId;

        $sub = $request->attributes->get('auth_sub');

        $request->validate([
            'state' => ['required'],
        ]);

        $stateJson = json_encode($request->input('state'));

        $exists = DB::table('app_states')
            ->where('app_id', $appId)
            ->where('user_sub', $sub)
            ->exists();

        if ($exists) {
            DB::table('app_states')
                ->where('app_id', $appId)
                ->where('user_sub', $sub)
                ->update([
                    'state_json' => $stateJson,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('app_states')->insert([
                'app_id' => $appId,
                'user_sub' => $sub,
                'state_json' => $stateJson,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function clearMapping(Request $request)
    {
        $auth = $request->attributes->get('auth');
        $lti = is_array($auth) ? ($auth['lti'] ?? null) : null;
        $issuer = is_array($lti) ? ($lti['issuer'] ?? null) : null;
        $deploymentId = is_array($lti) ? ($lti['deployment_id'] ?? null) : null;
        $resourceLinkId = is_array($lti) ? ($lti['resource_link_id'] ?? null) : null;

        Log::debug('clearMapping inputs', [
            'issuer' => $issuer,
            'deployment_id' => $deploymentId,
            'resource_link_id' => $resourceLinkId,
            'has_auth' => is_array($auth),
            'has_lti' => is_array($lti),
        ]);

        if (!is_string($issuer) || $issuer === '' ||
            !is_string($deploymentId) || $deploymentId === '' ||
            !is_string($resourceLinkId) || $resourceLinkId === '') {
            return response()->json(['error' => 'Missing LTI context for mapping.'], 400);
        }

        $deleted = DB::table('resource_link_apps')
            ->where('issuer', $issuer)
            ->where('deployment_id', $deploymentId)
            ->where('resource_link_id', $resourceLinkId)
            ->delete();

        Log::debug('clearMapping result', [
            'deleted' => $deleted,
        ]);

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    private function defaultJs(): string
    {
        return <<<'JS'
(async function(){
  const root = document.getElementById('app');
  const saved = await sdk.getState();
  const state = saved || { count: 0 };

  function render(){
    root.innerHTML = `
      <h2>Counter with resume</h2>
      <p>Count: <b>${state.count}</b></p>
      <button id="inc">+1</button>
      <button id="save">Save</button>
      <button id="reset">Reset</button>
      <p style="opacity:.7">Try saving, then refresh the page and run again.</p>
    `;
    document.getElementById('inc').onclick = () => { state.count++; render(); };
    document.getElementById('save').onclick = async () => { await sdk.setState(state); await sdk.notify({ variant: 'success', message: 'Saved' }); };
    document.getElementById('reset').onclick = async () => { state.count = 0; await sdk.setState(state); render(); };
  }

  render();
})();
JS;
    }
}
