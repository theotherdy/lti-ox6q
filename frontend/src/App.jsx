import { useState, useEffect } from 'react'
import Runner from './components/Runner'
import AuthDebug from './components/AuthDebug'

const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'

function jsonHeaders(token) {
  const h = { 'Content-Type': 'application/json' }
  if (token) h.Authorization = `Bearer ${token}`
  return h
}

function parseJwt(token) {
  if (!token || typeof token !== 'string') return null
  const parts = token.split('.')
  if (parts.length < 2) return null
  try {
    const payload = parts[1].replace(/-/g, '+').replace(/_/g, '/')
    const json = atob(payload.padEnd(payload.length + (4 - (payload.length % 4)) % 4, '='))
    return JSON.parse(json)
  } catch {
    return null
  }
}

export default function App() {
  const [activeTab, setActiveTab] = useState('app') // 'app' | 'auth'
  const [accessToken, setAccessToken] = useState(
    () => sessionStorage.getItem('accessToken')
  )
  const [bootstrapInfo, setBootstrapInfo] = useState(() => {
    const raw = sessionStorage.getItem('bootstrapInfo')
    if (!raw) return null
    try {
      return JSON.parse(raw)
    } catch {
      return null
    }
  })

  const [appPackage, setAppPackage] = useState(null)
  const [status, setStatus] = useState(null)
  const [clearing, setClearing] = useState(false)
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

  async function loadAppById(appId) {
    if (!accessToken) return
    setStatus(`Loading app ${appId}...`)
    try {
      const res = await fetch(`${API_BASE}/api/apps/${appId}/package`, {
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
      setAppPackage(body)
      sessionStorage.setItem('lastAppId', String(body.id))
      setStatus(`App ${body.id} loaded`)
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
      setBootstrapInfo((prev) => (prev ? { ...prev, app_id: body.id } : prev))
      sessionStorage.setItem('lastAppId', String(body.id))
      setStatus(`Generated app ${body.id} loaded`)
    } catch (e) {
      setStatus(String(e))
    } finally {
      setGenerating(false)
    }
  }

  useEffect(() => {
    // Auto-load mapped app (LTI) or last generated app (local dev)
    if (!accessToken) return
    if (appPackage) return

    const mappedAppId = bootstrapInfo?.app_id
    if (mappedAppId) {
      loadAppById(mappedAppId)
      return
    }

    const lastAppId = sessionStorage.getItem('lastAppId')
    if (!lastAppId) return
    loadAppById(lastAppId)
  }, [accessToken, appPackage, bootstrapInfo])

  useEffect(() => {
    if (bootstrapInfo) {
      sessionStorage.setItem('bootstrapInfo', JSON.stringify(bootstrapInfo))
      return
    }
    sessionStorage.removeItem('bootstrapInfo')
  }, [bootstrapInfo])

  useEffect(() => {
    if (!accessToken) return
    if (bootstrapInfo?.lti) return
    const payload = parseJwt(accessToken)
    if (!payload || !payload.lti) return
    setBootstrapInfo((prev) => (prev ? { ...prev, lti: payload.lti } : { lti: payload.lti }))
  }, [accessToken, bootstrapInfo])

  async function clearApp() {
    if (!accessToken) return
    setClearing(true)
    setStatus('Clearing app mapping...')

    try {
      const tokenLti = parseJwt(accessToken)?.lti
      const lti = bootstrapInfo?.lti || tokenLti
      const hasMapping = Boolean(lti?.issuer && lti?.deployment_id && lti?.resource_link_id)
      if (hasMapping) {
        const res = await fetch(`${API_BASE}/api/apps/mapping`, {
          method: 'DELETE',
          headers: jsonHeaders(accessToken),
        })
        const body = await res.json().catch(() => ({}))
        if (!res.ok) {
          setStatus(body.error || 'Failed to clear mapping')
          return
        }
        if (typeof body.deleted === 'number' && body.deleted === 0) {
          setStatus('No mapping row found to delete (cleared local selection).')
        }
      } else {
        setStatus('No LTI mapping in token; cleared local selection only.')
      }

      setAppPackage(null)
      setBootstrapInfo((prev) => (prev ? { ...prev, app_id: null } : prev))
      sessionStorage.removeItem('lastAppId')
      if (!hasMapping) {
        return
      }
      if (!status || status.startsWith('Clearing')) {
        setStatus('Cleared app selection')
      }
    } catch (e) {
      setStatus(`Clear error: ${String(e)}`)
    } finally {
      setClearing(false)
    }
  }

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
            <div style={{ marginTop: 8, display: 'flex', gap: 8 }}>
              <button
                onClick={generateApp}
                disabled={generating || !accessToken}
              >
                {generating ? 'Generating…' : 'Generate app'}
              </button>
              <button onClick={clearApp} disabled={!appPackage || clearing}>
                Clear app
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
