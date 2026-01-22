import React, { useMemo, useState } from 'react'
import Runner from './components/Runner.jsx'

const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'

function jsonHeaders(token) {
  const h = { 'Content-Type': 'application/json' }
  if (token) h.Authorization = `Bearer ${token}`
  return h
}

export default function App() {
  const [toolSupportJwt, setToolSupportJwt] = useState('')
  const [localToken, setLocalToken] = useState(null)
  const [status, setStatus] = useState(null)
  const [appPackage, setAppPackage] = useState(null)

  const prettyClaims = useMemo(() => {
    // Handy for debugging: decode payload without verifying.
    try {
      const parts = (toolSupportJwt || '').split('.')
      if (parts.length < 2) return null
      const payload = parts[1].replace(/-/g, '+').replace(/_/g, '/')
      const json = atob(payload.padEnd(payload.length + (4 - (payload.length % 4)) % 4, '='))
      return JSON.parse(json)
    } catch {
      return null
    }
  }, [toolSupportJwt])

  async function bootstrap() {
    setStatus('Bootstrapping...')
    setLocalToken(null)
    setAppPackage(null)

    try {
      const res = await fetch(`${API_BASE}/api/auth/bootstrap`, {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify({ tool_support_jwt: toolSupportJwt.trim() }),
      })

      const body = await res.json().catch(() => ({}))
      if (!res.ok) {
        setStatus(`Bootstrap failed: ${body.error || res.statusText}`)
        return
      }

      setLocalToken(body.access_token)
      setStatus(`Bootstrap OK (sub: ${body.subject})`)
    } catch (e) {
      setStatus(`Bootstrap error: ${String(e)}`)
    }
  }

  async function loadDummyApp() {
    setStatus('Loading dummy app package...')
    try {
      const res = await fetch(`${API_BASE}/api/apps/1/package`, {
        headers: jsonHeaders(localToken),
      })
      const body = await res.json().catch(() => ({}))
      if (!res.ok) {
        setStatus(`Load failed: ${body.error || res.statusText}`)
        return
      }
      setAppPackage(body)
      setStatus('App package loaded')
    } catch (e) {
      setStatus(`Load error: ${String(e)}`)
    }
  }

  return (
    <div style={{ fontFamily: 'system-ui', padding: 16, maxWidth: 1100, margin: '0 auto' }}>
      <h1 style={{ marginTop: 0 }}>lti-ox6q: Tool Support JWT → bootstrap → run</h1>

      <p style={{ opacity: 0.8 }}>
        Paste a Tool Support JWT from a previous launch, bootstrap to get a local API token, then run the dummy app.
      </p>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, alignItems: 'start' }}>
        <div>
          <h3>1) Paste Tool Support JWT</h3>
          <textarea
            value={toolSupportJwt}
            onChange={(e) => setToolSupportJwt(e.target.value)}
            rows={10}
            style={{ width: '100%', fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace' }}
            placeholder="eyJhbGciOi..."
          />
          <div style={{ marginTop: 8, display: 'flex', gap: 8 }}>
            <button onClick={bootstrap} disabled={!toolSupportJwt.trim()}>
              Bootstrap
            </button>
            <button onClick={() => { setToolSupportJwt(''); setLocalToken(null); setAppPackage(null); setStatus(null) }}>
              Clear
            </button>
          </div>

          <div style={{ marginTop: 12 }}>
            <div><b>Status:</b> {status || '—'}</div>
            <div><b>Local token:</b> {localToken ? '✅' : '—'}</div>
          </div>

          <div style={{ marginTop: 12 }}>
            <button onClick={loadDummyApp} disabled={!localToken}>
              Load dummy app package
            </button>
          </div>
        </div>

        <div>
          <h3>Debug (unsafe decode)</h3>
          <div style={{ fontSize: 12, opacity: 0.75, marginBottom: 8 }}>
            This is just for inspection. Verification happens in Laravel.
          </div>
          <pre style={{ whiteSpace: 'pre-wrap', background: '#f6f6f6', padding: 12, borderRadius: 8, minHeight: 250 }}>
            {prettyClaims ? JSON.stringify(prettyClaims, null, 2) : '—'}
          </pre>
        </div>
      </div>

      <hr style={{ margin: '24px 0' }} />

      <h3>2) Run the dummy app (sandboxed iframe)</h3>
      {appPackage ? (
        <Runner apiBase={API_BASE} token={localToken} pkg={appPackage} />
      ) : (
        <div style={{ opacity: 0.7 }}>Load the package first.</div>
      )}

      <hr style={{ margin: '24px 0' }} />

      <details>
        <summary>What this prototype proves</summary>
        <ul>
          <li>Bootstrap once with the Tool Support JWT</li>
          <li>Use a short-lived local token for a stateless API</li>
          <li>Run generated (here: dummy) JS in a sandboxed iframe</li>
          <li>Persist resume state through an SDK boundary (postMessage)</li>
        </ul>
      </details>
    </div>
  )
}
