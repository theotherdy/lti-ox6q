import React, { useEffect, useMemo, useRef, useState } from 'react'
import { Alert } from '@instructure/ui'

function jsonHeaders(token) {
  const h = { 'Content-Type': 'application/json' }
  if (token) h.Authorization = `Bearer ${token}`
  return h
}

function buildSrcDoc(pkg) {
  const title = pkg.title || 'Learning App'
  const html = pkg.html || "<div id='app'></div>"
  const css = pkg.css || ''
  const js = pkg.js || ''

  // A tiny RPC helper between iframe ↔ parent.
  return `<!doctype html>
<html>
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>${title}</title>
    <style>${css}</style>
  </head>
  <body>
    ${html}
    <script>
      (function(){
        const pending = new Map();
        let nextId = 1;

        function callParent(type, payload){
          return new Promise((resolve, reject) => {
            const id = nextId++;
            pending.set(id, {resolve, reject});
            window.parent.postMessage({ __ox6q: true, id, type, payload }, '*');
          });
        }

        window.addEventListener('message', (event) => {
          const msg = event.data;
          if (!msg || msg.__ox6q !== true || !msg.replyTo) return;
          const entry = pending.get(msg.replyTo);
          if (!entry) return;
          pending.delete(msg.replyTo);
          if (msg.ok) entry.resolve(msg.result);
          else entry.reject(new Error(msg.error || 'RPC failed'));
        });

        window.sdk = {
          getState: () => callParent('getState'),
          setState: (state) => callParent('setState', {state}),
          notify: (input) => {
            const payload =
            typeof input === 'string'
              ? { message: input, variant: 'info' }
              : { message: String(input?.message ?? ''), variant: String(input?.variant ?? 'info') }
              return callParent('notify', payload)
          }
        };
        
        // Nice dev-friendly shim: if generated apps call alert(), turn it into a notify.
        window.alert = (msg) => window.sdk.notify({ variant: 'info', message: String(msg) });
      })();
    </script>
    <script>
${js}
    </script>
  </body>
</html>`;
}

export default function Runner({ apiBase, token, pkg }) {
  const iframeRef = useRef(null)
  const [log, setLog] = useState([])
  const [notices, setNotices] = useState([]) //used to display notifications from iFramed app via callParent('notify', payload);

  const srcDoc = useMemo(() => buildSrcDoc(pkg), [pkg])

  //helper for pushing notices from iFramed app into Alert in this container app
  function pushNotice({ message, variant }) {
    const id = `${Date.now()}-${Math.random().toString(16).slice(2)}`
    const msg = String(message || '').slice(0, 300)

    // Instructure variants commonly include: info, success, warning, danger
    const v = (variant === 'error') ? 'danger' : variant
    const safeVariant = ['info', 'success', 'warning', 'danger'].includes(v) ? v : 'info'

    setNotices((xs) => [...xs, { id, message: msg, variant: safeVariant }].slice(-3))
    setTimeout(() => setNotices((xs) => xs.filter((n) => n.id !== id)), 3000)
  }

  useEffect(() => {
    const iframe = iframeRef.current
    if (!iframe) return

    async function handleRpc(event) {
      // Only accept messages from our iframe
      if (event.source !== iframe.contentWindow) return
      const msg = event.data
      if (!msg || msg.__ox6q !== true || !msg.id || !msg.type) return

      const reply = async (ok, resultOrError) => {
        iframe.contentWindow.postMessage(
          ok
            ? { __ox6q: true, replyTo: msg.id, ok: true, result: resultOrError }
            : { __ox6q: true, replyTo: msg.id, ok: false, error: String(resultOrError) },
          '*'
        )
      }

      try {
        if (msg.type === 'getState') {
          const res = await fetch(`${apiBase}/api/apps/${pkg.id}/state`, {
            headers: jsonHeaders(token),
          })
          const body = await res.json().catch(() => ({}))
          if (!res.ok) throw new Error(body.error || res.statusText)
          setLog((l) => [`getState → ${JSON.stringify(body.state)}`, ...l].slice(0, 8))
          await reply(true, body.state)
          return
        }

        if (msg.type === 'setState') {
          const res = await fetch(`${apiBase}/api/apps/${pkg.id}/state`, {
            method: 'PUT',
            headers: jsonHeaders(token),
            body: JSON.stringify({ state: msg.payload?.state }),
          })
          const body = await res.json().catch(() => ({}))
          if (!res.ok) throw new Error(body.error || res.statusText)
          setLog((l) => [`setState ← ${JSON.stringify(msg.payload?.state)}`, ...l].slice(0, 8))
          await reply(true, body)
          return
        }

        if (msg.type === 'notify') {
          pushNotice({
            message: msg.payload?.message,
            variant: msg.payload?.variant,
          })
          setLog((l) => [`notify ← ${JSON.stringify(msg.payload)}`, ...l].slice(0, 8))
          await reply(true, { ok: true })
          return
        }

        throw new Error(`Unknown RPC type: ${msg.type}`)
      } catch (e) {
        await reply(false, e)
      }
    }

    window.addEventListener('message', handleRpc)
    return () => window.removeEventListener('message', handleRpc)
  }, [apiBase, pkg, token])

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 16 }}>
      <div style={{ border: '1px solid #ddd', borderRadius: 12, overflow: 'hidden', position: 'relative' }}>
        {/* !!!!!Don't allow any powers other than allow-scripts without serious thought!!!!!! */}
        <iframe
          ref={iframeRef}
          title="Learning app"
          sandbox="allow-scripts"  
          srcDoc={srcDoc}
          style={{ width: '100%', height: 420, border: 0 }}
        />
        <div style={{ position: 'absolute', top: 12, right: 12, width: 360, display: 'grid', gap: 8 }}>
          {notices.map((n) => (
            <Alert key={n.id} variant={n.variant} margin="0">
              {n.message}
            </Alert>
          ))}
        </div>
      </div>
      <div style={{ background: '#f6f6f6', borderRadius: 12, padding: 12 }}>
        <div style={{ fontWeight: 600, marginBottom: 8 }}>SDK log</div>
        {log.length ? (
          <ul style={{ margin: 0, paddingLeft: 16 }}>
            {log.map((x, i) => (
              <li key={i} style={{ fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', fontSize: 12 }}>
                {x}
              </li>
            ))}
          </ul>
        ) : (
          <div style={{ opacity: 0.7 }}>No calls yet.</div>
        )}
      </div>
    </div>
  )
}
