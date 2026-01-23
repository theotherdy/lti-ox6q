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
  //for the LLM authoring
  const [prompt, setPrompt] = useState('')
  const [generating, setGenerating] = useState(false)

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

  async function generateApp() {
    if (!prompt.trim() || !accessToken) return

    setGenerating(true)
    setStatus('Generating app…')

    try {
      const res = await fetch(`${API_BASE}/api/apps/generate`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${accessToken}`,
        },
        body: JSON.stringify({ prompt }),
      })

      const body = await res.json().catch(() => ({}))
      if (!res.ok) {
        setStatus(body.error || 'Generation failed')
        return
      }

      setAppPackage(body)
      setStatus('Generated app loaded')
    } catch (e) {
      setStatus(String(e))
    } finally {
      setGenerating(false)
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
          {/* LLM authoring UI */}
          <div style={{ marginBottom: 16 }}>
            <textarea
              rows={3}
              placeholder="Describe the learning activity you want…"
              value={prompt}
              onChange={(e) => setPrompt(e.target.value)}
              style={{ width: '100%', resize: 'vertical' }}
            />
            <div style={{ marginTop: 8 }}>
              <button
                onClick={generateApp}
                disabled={generating || !accessToken}
              >
                {generating ? 'Generating…' : 'Generate app'}
              </button>
            </div>
          </div>

          {/* Status */}
          {status && (
            <div style={{ marginBottom: 12, opacity: 0.8 }}>
              {status}
            </div>
          )}

          {/* App runner */}
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
