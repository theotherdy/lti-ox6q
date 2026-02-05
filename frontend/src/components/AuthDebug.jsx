import { useState } from 'react'

export default function AuthDebug({
  accessToken,
  setAccessToken,
  bootstrapInfo,
  setBootstrapInfo,
}) {
  const [jwt, setJwt] = useState('')
  const [error, setError] = useState(null)
  const [loading, setLoading] = useState(false)

  async function bootstrap() {
    setError(null)
    setLoading(true)

    try {
      const res = await fetch('http://localhost:8000/api/auth/bootstrap', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tool_support_jwt: jwt }),
      })

      if (!res.ok) {
        throw new Error(`Bootstrap failed (${res.status})`)
      }

      const data = await res.json()
      setAccessToken(data.access_token)
      setBootstrapInfo(data)
    } catch (e) {
      setError(e.message)
    } finally {
      setLoading(false)
    }
  }

  const preStyle = {
    whiteSpace: 'pre-wrap',
    wordBreak: 'break-all',
    overflow: 'auto',
    maxHeight: '200px',
    fontSize: '12px',
    background: '#f5f5f5',
    padding: '8px',
    borderRadius: '4px',
  }

  return (
    <div style={{ maxWidth: '100%', overflow: 'hidden' }}>
      <h2 style={{ fontSize: '1.2rem', margin: '0 0 12px 0' }}>Auth / Bootstrap (Debug)</h2>

      <textarea
        rows={4}
        style={{ width: '100%', boxSizing: 'border-box' }}
        placeholder="Paste Tool Support / Canvas JWT here"
        value={jwt}
        onChange={(e) => setJwt(e.target.value)}
      />

      <div style={{ marginTop: 8 }}>
        <button onClick={bootstrap} disabled={loading}>
          {loading ? 'Bootstrapping…' : 'Bootstrap'}
        </button>
      </div>

      {error && (
        <div style={{ color: 'red', marginTop: 8 }}>
          {error}
        </div>
      )}

      {bootstrapInfo && (
        <>
          <h3 style={{ fontSize: '1rem', margin: '12px 0 8px 0' }}>Bootstrap result</h3>
          <pre style={preStyle}>{JSON.stringify(bootstrapInfo, null, 2)}</pre>
        </>
      )}

      {accessToken && (
        <>
          <h3 style={{ fontSize: '1rem', margin: '12px 0 8px 0' }}>Local access token</h3>
          <pre style={preStyle}>{accessToken}</pre>
        </>
      )}

      <div style={{ marginTop: 12 }}>
        <button onClick={() => {
            setBootstrapInfo(null)
            sessionStorage.removeItem('accessToken')
            window.location.reload()
            }}>
            Reset session (debug)
        </button>
      </div>
    </div>
  )
}
