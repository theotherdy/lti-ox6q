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

        $row = DB::table('apps')->where('id', $appId)->first();

        if (!$row) {
            return response()->json(['error' => 'App not found.'], 404);
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
        $ltiUserId = $this->getLtiUserId($sub);

        $row = DB::table('app_states')
            ->where('app_id', $appId)
            ->where('lti_user_id', $ltiUserId)
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
        $ltiUserId = $this->getLtiUserId($sub);

        $request->validate([
            'state' => ['required'],
        ]);

        $stateJson = json_encode($request->input('state'));

        $exists = DB::table('app_states')
            ->where('app_id', $appId)
            ->where('lti_user_id', $ltiUserId)
            ->exists();

        if ($exists) {
            DB::table('app_states')
                ->where('app_id', $appId)
                ->where('lti_user_id', $ltiUserId)
                ->update([
                    'state_json' => $stateJson,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('app_states')->insert([
                'app_id' => $appId,
                'lti_user_id' => $ltiUserId,
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

        $deleted = DB::table('resource_links')
            ->where('issuer', $issuer)
            ->where('deployment_id', $deploymentId)
            ->where('resource_link_id', $resourceLinkId)
            ->delete();

        Log::debug('clearMapping result', [
            'deleted' => $deleted,
        ]);

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    private function getLtiUserId(string $sub): int
    {
        $now = now();

        DB::table('lti_users')->insertOrIgnore([
            'sub' => $sub,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('lti_users')
            ->where('sub', $sub)
            ->update(['updated_at' => $now]);

        $userId = DB::table('lti_users')->where('sub', $sub)->value('id');

        return (int) $userId;
    }
}
