import { useState, useEffect } from 'react'
import Runner from './components/Runner'
import AuthDebug from './components/AuthDebug'
import { View } from '@instructure/ui-view'
import { Flex } from '@instructure/ui-flex'
import { Button } from '@instructure/ui-buttons'
import { TextArea } from '@instructure/ui-text-area'
import { Alert } from '@instructure/ui-alerts'
import { DrawerLayout } from '@instructure/ui-drawer-layout'
import { Tabs } from '@instructure/ui-tabs'
import { Heading } from '@instructure/ui-heading'
import { Text } from '@instructure/ui-text'

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
  // Revision approval workflow
  const [pendingRevision, setPendingRevision] = useState(() => {
    const raw = sessionStorage.getItem('pendingRevision')
    if (!raw) return null
    try {
      return JSON.parse(raw)
    } catch {
      return null
    }
  })
  const [originalApp, setOriginalApp] = useState(null)
  // Drawer layout state
  const [isTrayOpen, setIsTrayOpen] = useState(true)

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

    const isRevising = appPackage?.id
    setGenerating(true)
    setStatus(isRevising ? 'Revising app…' : 'Generating app…')

    try {
      const requestBody = { prompt }
      if (isRevising) {
        requestBody.app_id = appPackage.id
        requestBody.preview = true  // Don't save to DB yet
        setOriginalApp(appPackage)   // Save original for revert
      }

      const res = await fetch(`${API_BASE}/api/apps/generate`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${accessToken}`,
        },
        body: JSON.stringify(requestBody),
      })

      const body = await res.json().catch(() => ({}))
      if (!res.ok) {
        setStatus(body.error || (isRevising ? 'Revision failed' : 'Generation failed'))
        return
      }

      if (isRevising) {
        // Store as pending revision (not saved to DB yet)
        setPendingRevision(body)
        setStatus('Revision ready. Review and Keep or Revert.')
      } else {
        // New app - save immediately
        setAppPackage(body)
        setBootstrapInfo((prev) => (prev ? { ...prev, app_id: body.id } : prev))
        sessionStorage.setItem('lastAppId', String(body.id))
        setStatus(`App generated: ${body.title}`)
      }
      setPrompt('') // Clear prompt after successful generation/revision
    } catch (e) {
      setStatus(String(e))
    } finally {
      setGenerating(false)
    }
  }

  async function keepRevision() {
    if (!pendingRevision || !accessToken) return

    setStatus('Saving revision...')

    try {
      const res = await fetch(`${API_BASE}/api/apps/${pendingRevision.id}/save-revision`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${accessToken}`,
        },
        body: JSON.stringify({
          title: pendingRevision.title,
          html: pendingRevision.html,
          css: pendingRevision.css,
          js: pendingRevision.js,
        }),
      })

      const body = await res.json().catch(() => ({}))
      if (!res.ok) {
        setStatus(body.error || 'Failed to save revision')
        return
      }

      // Success - make pending revision the current app
      setAppPackage(pendingRevision)
      setPendingRevision(null)
      setOriginalApp(null)
      setStatus('Revision saved successfully')
    } catch (e) {
      setStatus(`Save error: ${String(e)}`)
    }
  }

  function revertRevision() {
    // Restore original app
    if (originalApp) {
      setAppPackage(originalApp)
    }

    setPendingRevision(null)
    setOriginalApp(null)
    setStatus('Reverted to original version')
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

  // Persist pending revision to sessionStorage
  useEffect(() => {
    if (pendingRevision) {
      sessionStorage.setItem('pendingRevision', JSON.stringify(pendingRevision))
    } else {
      sessionStorage.removeItem('pendingRevision')
    }
  }, [pendingRevision])

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
      setPrompt('') // Clear prompt for fresh start
      setPendingRevision(null) // Clear pending revision
      setOriginalApp(null) // Clear original app
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
    <DrawerLayout>
      <DrawerLayout.Tray
        label="Controls"
        open={isTrayOpen}
        placement="start"
        onDismiss={() => setIsTrayOpen(false)}
      >
        <View as="div" padding="medium">
          <Tabs
            onRequestTabChange={(event, { index }) => {
              setActiveTab(index === 0 ? 'app' : 'auth')
            }}
          >
            <Tabs.Panel
              renderTitle="Application"
              isSelected={activeTab === 'app'}
            >
              {/* Pending Revision Approval */}
              {pendingRevision && (
                <Alert
                  variant="warning"
                  margin="small 0"
                  renderCloseButtonLabel="Close"
                >
                  <Flex direction="column" gap="small">
                    <Heading level="h4">Revision Preview</Heading>
                    <Text>Review the changes below. Keep to save permanently, or Revert to discard.</Text>
                    <Flex gap="small">
                      <Button onClick={keepRevision} color="success">
                        Keep Revision
                      </Button>
                      <Button onClick={revertRevision} color="danger">
                        Revert to Original
                      </Button>
                    </Flex>
                  </Flex>
                </Alert>
              )}

              {/* LLM authoring UI */}
              <View as="div" margin="small 0">
                <TextArea
                  label="App Description"
                  placeholder={
                    appPackage
                      ? "What changes would you like to make?…"
                      : "Describe the learning activity you want…"
                  }
                  value={prompt}
                  onChange={(e) => setPrompt(e.target.value)}
                  disabled={generating}
                  height="100px"
                />
                <Flex gap="small" margin="small 0">
                  <Button
                    onClick={generateApp}
                    disabled={generating || !prompt.trim() || !accessToken || pendingRevision}
                    color="primary"
                  >
                    {generating
                      ? (appPackage ? 'Revising…' : 'Generating…')
                      : (appPackage ? 'Revise app' : 'Generate app')
                    }
                  </Button>
                  {appPackage && (
                    <Button
                      onClick={clearApp}
                      disabled={clearing || generating}
                    >
                      {clearing ? 'Clearing…' : 'Start Over'}
                    </Button>
                  )}
                </Flex>
              </View>

              {/* Status */}
              {status && (
                <View as="div" margin="small 0">
                  <Text color="secondary">{status}</Text>
                </View>
              )}
            </Tabs.Panel>

            <Tabs.Panel
              renderTitle="Auth / Bootstrap"
              isSelected={activeTab === 'auth'}
            >
              <AuthDebug
                accessToken={accessToken}
                setAccessToken={setToken}
                bootstrapInfo={bootstrapInfo}
                setBootstrapInfo={setBootstrapInfo}
              />
            </Tabs.Panel>
          </Tabs>
        </View>
      </DrawerLayout.Tray>

      <DrawerLayout.Content label="App Preview">
        <View as="div" padding="medium">
          <Runner
            apiBase={API_BASE}
            token={accessToken}
            pkg={pendingRevision || appPackage}
          />
        </View>
      </DrawerLayout.Content>
    </DrawerLayout>
  )
}
