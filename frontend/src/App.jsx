import { useState, useEffect } from 'react'
import Runner from './components/Runner'
import AuthDebug from './components/AuthDebug'

const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'

export default function App() {
  const [activeTab, setActiveTab] = useState('app') // 'app' | 'auth'
  const [accessToken, setAccessToken] = useState(
    () => sessionStorage.getItem('accessToken')
  )
  const [bootstrapInfo, setBootstrapInfo] = useState(null)

  const [appPackage, setAppPackage] = useState(null)
  const [status, setStatus] = useState(null)

  function setToken(token) {
    if (token) {
      sessionStorage.setItem('accessToken', token)
      setAccessToken(token)
    } else {
      sessionStorage.removeItem('accessToken')
      setAccessToken(null)
    }
  }

  async function loadDummyApp() {
    if (!accessToken) return
    setStatus('Loading dummy app package...')
    try {
      const res = await fetch(`${API_BASE}/api/apps/1/package`, {
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${accessToken}`,
        },
      })
      const body = await res.json().catch(() => ({}))
      if (!res.ok) {
        setStatus(`Load failed: ${body.error || res.statusText}`)
        return
      }
      if (res.status === 401) {
        setToken(null)
        setStatus('Session expired — please re-bootstrap')
        return
      }
      setAppPackage(body)
      setStatus('Dummy app loaded')
    } catch (e) {
      setStatus(`Load error: ${String(e)}`)
    }
  }

  useEffect(() => {
    // Auto-load app when we have a token but no package yet
    if (!accessToken) return
    if (appPackage) return

    loadDummyApp()
  }, [accessToken, appPackage])

  return (
    <div style={{ padding: 16 }}>
      {/* Tabs */}
      <div style={{ display: 'flex', gap: 12, marginBottom: 16 }}>
        <button
          onClick={() => setActiveTab('app')}
          style={{ fontWeight: activeTab === 'app' ? 'bold' : 'normal' }}
        >
          Application
        </button>

        <button
          onClick={() => setActiveTab('auth')}
          style={{ fontWeight: activeTab === 'auth' ? 'bold' : 'normal' }}
        >
          Auth / Bootstrap
        </button>
      </div>

      {/* Application tab */}
      {activeTab === 'app' && (
        <>
          {status && (
            <div style={{ marginBottom: 12, opacity: 0.8 }}>
              {status}
            </div>
          )}

          <Runner
            apiBase={API_BASE}
            token={accessToken}
            pkg={appPackage}
          />
        </>
      )}

      {/* Auth tab */}
      {activeTab === 'auth' && (
        <AuthDebug
          accessToken={accessToken}
          setAccessToken={setToken}
          bootstrapInfo={bootstrapInfo}
          setBootstrapInfo={setBootstrapInfo}
        />
      )}
    </div>
  )
}
