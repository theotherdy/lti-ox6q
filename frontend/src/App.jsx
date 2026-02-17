import { useState, useEffect, useRef, useCallback } from 'react'
import { LtiTokenRetriever, LtiPageSettings, LtiHeightLimit } from '@oxctl/ui-lti'
import Runner from './components/Runner'
import StructuredQuestionRunner from './components/StructuredQuestionRunner'
import { View } from '@instructure/ui-view'
import { Flex } from '@instructure/ui-flex'
import { Button } from '@instructure/ui-buttons'
import { IconButton } from '@instructure/ui-buttons'
import { TextArea } from '@instructure/ui-text-area'
import { Alert } from '@instructure/ui-alerts'
import { DrawerLayout } from '@instructure/ui-drawer-layout'
import { Heading } from '@instructure/ui-heading'
import { Text } from '@instructure/ui-text'
import { Spinner } from '@instructure/ui-spinner'
import { IconHamburgerLine } from '@instructure/ui-icons'
import { ScreenReaderContent } from '@instructure/ui-a11y-content'

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
  const [errorMessage, setErrorMessage] = useState(null)
  const [clearing, setClearing] = useState(false)
  //for the LLM authoring
  const [prompt, setPrompt] = useState('')
  const [generating, setGenerating] = useState(false)
  const [elapsedTime, setElapsedTime] = useState(0)
  const timerRef = useRef(null)
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

  // Handle JWT from LtiTokenRetriever (Tool Support launch)
  const handleLtiJwt = useCallback(async (toolSupportJwt, server) => {
    console.log('LTI JWT received from Tool Support:', { server, jwtPreview: toolSupportJwt?.substring(0, 50) + '...' })

    try {
      const res = await fetch(`${API_BASE}/api/auth/bootstrap`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tool_support_jwt: toolSupportJwt }),
      })

      if (!res.ok) {
        const body = await res.json().catch(() => ({}))
        throw new Error(body.error || `Bootstrap failed (${res.status})`)
      }

      const data = await res.json()
      setToken(data.access_token)
      setBootstrapInfo(data)
      setErrorMessage(null)
    } catch (e) {
      console.error('LTI bootstrap error:', e)
      setErrorMessage(e.message)
    }
  }, [])

  function setToken(token) {
    if (token) {
      sessionStorage.setItem('accessToken', token)
      setAccessToken(token)
    } else {
      sessionStorage.removeItem('accessToken')
      setAccessToken(null)
    }
  }

  // Auto-refresh token before expiry (at 95% of lifetime)
  useEffect(() => {
    if (!accessToken || !bootstrapInfo?.expires_in) return

    // Refresh at 95% of token lifetime (e.g., 57s for 60s token, 28.5min for 30min token)
    const expiresInMs = bootstrapInfo.expires_in * 1000
    const refreshInterval = expiresInMs * 0.95

    console.log(`Token auto-refresh scheduled in ${Math.round(refreshInterval / 1000)}s (95% of ${bootstrapInfo.expires_in}s lifetime)`)

    const interval = setInterval(async () => {
      try {
        const res = await fetch(`${API_BASE}/api/auth/refresh`, {
          method: 'POST',
          headers: { Authorization: `Bearer ${accessToken}` },
        })

        if (res.ok) {
          const data = await res.json()
          setToken(data.access_token)
          console.log('Token refreshed successfully')
          setErrorMessage(null)
          return
        } else {
          console.warn('Token refresh failed, user may need to re-launch')
          if (res.status === 401) {
            setToken(null)
            setErrorMessage('Session expired — please re-launch.')
          }
        }
      } catch (e) {
        console.error('Token refresh error:', e)
      }
    }, refreshInterval)

    return () => clearInterval(interval)
  }, [accessToken, bootstrapInfo])

  async function loadAppById(appId) {
    if (!accessToken) return
    try {
      const res = await fetch(`${API_BASE}/api/apps/${appId}/package`, {
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${accessToken}`,
        },
      })
      const body = await res.json().catch(() => ({}))
      if (res.status === 401) {
        setToken(null)
        setErrorMessage('Session expired — please re-launch.')
        return
      }
      if (!res.ok) {
        setErrorMessage(`Load failed: ${body.error || res.statusText}`)
        return
      }
      setAppPackage(body)
      sessionStorage.setItem('lastAppId', String(body.id))
      setErrorMessage(null)
    } catch (e) {
      setErrorMessage(`Load error: ${String(e)}`)
    }
  }

  async function generateApp() {
    if (!prompt.trim() || !accessToken) return

    const isRevising = Boolean(appPackage?.id)
    setGenerating(true)
    setElapsedTime(0)
    setErrorMessage(null)

    // Start elapsed time counter
    timerRef.current = setInterval(() => {
      setElapsedTime((prev) => prev + 1)
    }, 1000)

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
      if (res.status === 401) {
        setToken(null)
        setErrorMessage('Session expired — please re-launch.')
        return
      }
      if (!res.ok) {
        setErrorMessage(body.error || (isRevising ? 'Revision failed' : 'Generation failed'))
        return
      }

      if (isRevising) {
        // Store pending revision for both open and structured modes.
        setPendingRevision(body)
      } else {
        setAppPackage(body)
        if (body.id) {
          setBootstrapInfo((prev) => (prev ? { ...prev, app_id: body.id } : prev))
          sessionStorage.setItem('lastAppId', String(body.id))
        }
      }
      setPrompt('') // Clear prompt after successful generation/revision
    } catch (e) {
      setErrorMessage(String(e))
    } finally {
      // Stop the timer
      if (timerRef.current) {
        clearInterval(timerRef.current)
        timerRef.current = null
      }
      setGenerating(false)
    }
  }

  async function keepRevision() {
    if (!pendingRevision || !accessToken) return

    try {
      const res = await fetch(`${API_BASE}/api/apps/${pendingRevision.id}/save-revision`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${accessToken}`,
        },
        body: JSON.stringify(
          pendingRevision.kind === 'structured_question_set'
            ? {
                kind: 'structured_question_set',
                schema_version: pendingRevision.schema_version,
                title: pendingRevision.title,
                questions: pendingRevision.questions,
                meta: pendingRevision.meta || {},
              }
            : {
                kind: 'open_interaction',
                title: pendingRevision.title,
                html: pendingRevision.html,
                css: pendingRevision.css,
                js: pendingRevision.js,
              }
        ),
      })

      const body = await res.json().catch(() => ({}))
      if (res.status === 401) {
        setToken(null)
        setErrorMessage('Session expired — please re-launch.')
        return
      }
      if (!res.ok) {
        setErrorMessage(body.error || 'Failed to save revision')
        return
      }

      // Success - make pending revision the current app
      setAppPackage(pendingRevision)
      setPendingRevision(null)
      setOriginalApp(null)
      setErrorMessage(null)
    } catch (e) {
      setErrorMessage(`Save error: ${String(e)}`)
    }
  }

  function revertRevision() {
    // Restore original app
    if (originalApp) {
      setAppPackage(originalApp)
    }

    setPendingRevision(null)
    setOriginalApp(null)
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

  // Cleanup timer on unmount
  useEffect(() => {
    return () => {
      if (timerRef.current) {
        clearInterval(timerRef.current)
      }
    }
  }, [])

  async function clearApp() {
    if (!accessToken) return
    setClearing(true)
    setErrorMessage(null)

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
        if (res.status === 401) {
          setToken(null)
          setErrorMessage('Session expired — please re-launch.')
          return
        }
        if (!res.ok) {
          setErrorMessage(body.error || 'Failed to clear mapping')
          return
        }
        if (typeof body.deleted === 'number' && body.deleted === 0) {
          // No-op: user can see state in the preview panel.
        }
      } else {
        // No-op: user can see state in the preview panel.
      }

      setAppPackage(null)
      setPrompt('') // Clear prompt for fresh start
      setPendingRevision(null) // Clear pending revision
      setOriginalApp(null) // Clear original app
      setBootstrapInfo((prev) => (prev ? { ...prev, app_id: null } : prev))
      sessionStorage.removeItem('lastAppId')
    } catch (e) {
      setErrorMessage(`Clear error: ${String(e)}`)
    } finally {
      setClearing(false)
    }
  }

  // Check if this is an LTI launch (has token param from Tool Support)
  const urlParams = new URLSearchParams(window.location.search)
  const isLtiLaunch = urlParams.has('token')

  // Main app content
  const appContent = (
    <DrawerLayout minHeight="100vh">
      <DrawerLayout.Tray
        label="Controls"
        open={isTrayOpen}
        placement="start"
        onDismiss={() => setIsTrayOpen(false)}
      >
        <View as="div" padding="medium">
          <Flex justifyItems="end" margin="0 0 small 0">
            <IconButton
              screenReaderLabel="Close controls"
              onClick={() => setIsTrayOpen(false)}
              size="small"
              withBackground={false}
              withBorder={false}
            >
              <IconHamburgerLine />
            </IconButton>
          </Flex>

          {/* Errors */}
          {errorMessage && (
            <Alert
              variant="error"
              margin="0 0 small 0"
              renderCloseButtonLabel="Close"
              onDismiss={() => setErrorMessage(null)}
              variantScreenReaderLabel="Error, "
            >
              {errorMessage}
            </Alert>
          )}

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
              label={<ScreenReaderContent>App description</ScreenReaderContent>}
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

          {/* Loading indicator with timer */}
          {generating && (
            <View as="div" margin="small 0" padding="small" background="secondary">
              <Flex alignItems="center" gap="small">
                <Spinner size="x-small" renderTitle="Generating" />
                <Text>
                  {appPackage ? 'Revising' : 'Generating'} app… {elapsedTime}s
                </Text>
              </Flex>
            </View>
          )}
        </View>
      </DrawerLayout.Tray>

      <DrawerLayout.Content label="App Preview">
        <View as="div" padding="medium">
          {!isTrayOpen && (
            <Flex margin="0 0 small 0">
              <IconButton
                screenReaderLabel="Show controls"
                onClick={() => setIsTrayOpen(true)}
                size="small"
                withBackground={false}
                withBorder={false}
              >
                <IconHamburgerLine />
              </IconButton>
            </Flex>
          )}
          {(pendingRevision || appPackage)?.kind === 'structured_question_set' && (
            <StructuredQuestionRunner pkg={pendingRevision || appPackage} />
          )}
          {(pendingRevision || appPackage)?.kind !== 'structured_question_set' && (
            <Runner
              apiBase={API_BASE}
              token={accessToken}
              pkg={pendingRevision || appPackage}
              onError={setErrorMessage}
            />
          )}
        </View>
      </DrawerLayout.Content>
    </DrawerLayout>
  )

  // If LTI launch, wrap with LtiTokenRetriever to fetch JWT from Tool Support
  // Otherwise render directly (for local dev or when already authenticated)
  if (isLtiLaunch && !accessToken) {
    return (
      <LtiPageSettings>
        <LtiHeightLimit>
          <LtiTokenRetriever handleJwt={handleLtiJwt}>
            {appContent}
          </LtiTokenRetriever>
        </LtiHeightLimit>
      </LtiPageSettings>
    )
  }

  // Direct access (no LTI) or already authenticated
  return appContent
}
