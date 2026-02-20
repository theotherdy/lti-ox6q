import { useState, useEffect, useLayoutEffect, useRef, useCallback } from 'react'
import { LtiTokenRetriever, LtiPageSettings, LtiHeightLimit } from '@oxctl/ui-lti'
import Runner from './components/Runner'
import StructuredRunnerFrame from './components/StructuredRunnerFrame'
import DeepLinkForm from './components/DeepLinkForm'
import { View } from '@instructure/ui-view'
import { Flex } from '@instructure/ui-flex'
import { Button, IconButton } from '@instructure/ui-buttons'
import { TextArea } from '@instructure/ui-text-area'
import { Alert } from '@instructure/ui-alerts'
import { DrawerLayout } from '@instructure/ui-drawer-layout'
import { Heading } from '@instructure/ui-heading'
import { Text } from '@instructure/ui-text'
import { Spinner } from '@instructure/ui-spinner'
import { IconHamburgerLine, IconXLine, IconFullScreenLine, IconExitFullScreenLine } from '@instructure/ui-icons'
import { ScreenReaderContent } from '@instructure/ui-a11y-content'

const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'
const MODE_STRUCTURED = 'structured_question_set'
const MODE_OPEN = 'open_interaction'
const DEFAULT_STRUCTURED_TYPE = 'multiple_choice_single_answer'
const DEEP_LINK_IFRAME_WIDTH = 1000
const DEEP_LINK_IFRAME_HEIGHT = 520
const DEEP_LINK_PREVIEW_MIN_HEIGHT = 560
const EDITOR_COMPACT_HEIGHT = 600

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

function hasInstructorRole(roles) {
  if (!Array.isArray(roles)) return false
  return roles.some((role) => {
    if (typeof role !== 'string') return false
    const collapsed = role.toLowerCase().replace(/[^a-z]/g, '')
    return (
      collapsed.includes('instructor') ||
      collapsed.includes('teacher') ||
      collapsed.includes('teachingassistant') ||
      collapsed.includes('contentdeveloper') ||
      collapsed.includes('administrator') ||
      collapsed.includes('designer') ||
      collapsed.includes('tutor')
    )
  })
}

export default function App() {
  const urlParams = new URLSearchParams(window.location.search)
  const isLtiLaunch = urlParams.has('token')

  const [accessToken, setAccessToken] = useState(
    () => (isLtiLaunch ? null : sessionStorage.getItem('accessToken'))
  )
  const [bootstrapInfo, setBootstrapInfo] = useState(() => {
    if (isLtiLaunch) return null
    const raw = sessionStorage.getItem('bootstrapInfo')
    if (!raw) return null
    try {
      return JSON.parse(raw)
    } catch {
      return null
    }
  })
  const [ltiServer, setLtiServer] = useState(() => (isLtiLaunch ? null : sessionStorage.getItem('ltiServer')))
  const [toolSupportJwt, setToolSupportJwt] = useState(() => (isLtiLaunch ? null : sessionStorage.getItem('toolSupportJwt')))

  const [appPackage, setAppPackage] = useState(null)
  const [errorMessage, setErrorMessage] = useState(null)
  const [clearing, setClearing] = useState(false)
  const [prompt, setPrompt] = useState('')
  const [generating, setGenerating] = useState(false)
  const [elapsedTime, setElapsedTime] = useState(0)
  const timerRef = useRef(null)

  const [pendingRevision, setPendingRevision] = useState(() => {
    if (isLtiLaunch) return null
    const raw = sessionStorage.getItem('pendingRevision')
    if (!raw) return null
    try {
      return JSON.parse(raw)
    } catch {
      return null
    }
  })
  const [originalApp, setOriginalApp] = useState(null)

  const [isTrayOpen, setIsTrayOpen] = useState(true)
  const [generationMode, setGenerationMode] = useState(MODE_STRUCTURED)
  const [questionType, setQuestionType] = useState(DEFAULT_STRUCTURED_TYPE)
  const [modeLocked, setModeLocked] = useState(false)
  const [convertMode, setConvertMode] = useState(false)

  const [deepLinkingJwt, setDeepLinkingJwt] = useState('')
  const [insertingDeepLink, setInsertingDeepLink] = useState(false)

  const [editorOpen, setEditorOpen] = useState(false)
  const [isEditorFullScreen, setIsEditorFullScreen] = useState(false)
  const [canvasViewportHeight, setCanvasViewportHeight] = useState(null)
  const [showStateResetWarning, setShowStateResetWarning] = useState(false)
  const [stateSummary, setStateSummary] = useState(null)
  const [resetNonInstructorStateOnSave, setResetNonInstructorStateOnSave] = useState(false)

  const refreshInFlightRef = useRef(null)
  const contentRootRef = useRef(null)
  const isEditorFullScreenRef = useRef(false)
  const canvasViewportHeightRef = useRef(null)
  const closeButtonContainerRef = useRef(null)
  const preEditHeightRef = useRef(null)
  // Keep refs in sync on every render so effect closures always read current values
  isEditorFullScreenRef.current = isEditorFullScreen
  canvasViewportHeightRef.current = canvasViewportHeight

  useEffect(() => {
    if (!isLtiLaunch) return
    sessionStorage.removeItem('accessToken')
    sessionStorage.removeItem('bootstrapInfo')
    sessionStorage.removeItem('toolSupportJwt')
    sessionStorage.removeItem('ltiServer')
    sessionStorage.removeItem('pendingRevision')
  }, [isLtiLaunch])

  useEffect(() => {
    if (!isLtiLaunch) return
    const handleMessage = (e) => {
      let data = e.data
      if (typeof data === 'string') {
        try { data = JSON.parse(data) } catch { return }
      }
      if (data?.subject === 'lti.fetchWindowSize.response') {
        if (Number.isFinite(data.height) && data.height > 0) {
          setCanvasViewportHeight(data.height)
        }
      }
    }
    window.addEventListener('message', handleMessage)
    const msg = JSON.stringify({ subject: 'lti.fetchWindowSize' })
    window.parent.postMessage(msg, '*')
    if (window.top && window.top !== window.parent) {
      window.top.postMessage(msg, '*')
    }
    return () => window.removeEventListener('message', handleMessage)
  }, [isLtiLaunch])

  const tokenPayload = parseJwt(accessToken)
  const lti = bootstrapInfo?.lti || tokenPayload?.lti || null
  const inferredLaunchMode = isLtiLaunch
    ? (lti?.message_type === 'LtiDeepLinkingRequest' ? 'deep_linking' : 'resource')
    : 'local'
  const launchMode = bootstrapInfo?.launch_mode || tokenPayload?.launch_mode || lti?.launch_mode || inferredLaunchMode
  const isDeepLinkLaunch = isLtiLaunch && launchMode === 'deep_linking'
  const isResourceLaunch = isLtiLaunch && launchMode === 'resource'
  const isInstructor = Boolean(lti?.is_instructor) || hasInstructorRole(tokenPayload?.roles)
  const deepLinkReturnUrl = lti?.deep_linking_settings?.deep_link_return_url || null
  const launchReturnUrl = lti?.launch_presentation?.return_url || null
  const targetLinkUri = lti?.target_link_uri || `${window.location.origin}${window.location.pathname}`

  const handleLtiJwt = useCallback(async (receivedToolSupportJwt, server) => {
    setToolSupportJwt(receivedToolSupportJwt)
    setLtiServer(server)
    sessionStorage.setItem('toolSupportJwt', receivedToolSupportJwt)
    sessionStorage.setItem('ltiServer', server)

    try {
      const res = await fetch(`${API_BASE}/api/auth/bootstrap`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tool_support_jwt: receivedToolSupportJwt }),
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

  async function refreshAccessToken(tokenToRefresh = accessToken, opts = {}) {
    const { clearOnUnauthorized = true } = opts
    if (!tokenToRefresh) return null

    if (refreshInFlightRef.current) {
      return refreshInFlightRef.current
    }

    refreshInFlightRef.current = (async () => {
      try {
        const res = await fetch(`${API_BASE}/api/auth/refresh`, {
          method: 'POST',
          headers: { Authorization: `Bearer ${tokenToRefresh}` },
        })

        if (!res.ok) {
          if (res.status === 401 && clearOnUnauthorized) {
            setToken(null)
            setErrorMessage('Session expired — please re-launch.')
          }
          return null
        }

        const data = await res.json().catch(() => ({}))
        if (!data?.access_token) return null
        setToken(data.access_token)
        setErrorMessage(null)
        return data.access_token
      } catch (e) {
        console.error('Token refresh error:', e)
        return null
      }
    })()

    const refreshedToken = await refreshInFlightRef.current
    refreshInFlightRef.current = null
    return refreshedToken
  }

  async function fetchJsonWithAutoRefresh(url, init = {}) {
    const token = accessToken
    const originalHeaders = init.headers || {}
    const headers = token && !originalHeaders.Authorization
      ? { ...originalHeaders, Authorization: `Bearer ${token}` }
      : originalHeaders

    let res = await fetch(url, { ...init, headers })
    if (res.status !== 401) {
      const body = await res.json().catch(() => ({}))
      return { res, body }
    }

    const refreshedToken = await refreshAccessToken(token)
    if (!refreshedToken) {
      const body = await res.json().catch(() => ({}))
      return { res, body }
    }

    const retryHeaders = { ...originalHeaders, Authorization: `Bearer ${refreshedToken}` }
    res = await fetch(url, { ...init, headers: retryHeaders })
    const body = await res.json().catch(() => ({}))
    return { res, body }
  }

  async function loadAppById(appId, opts = {}) {
    const { isMappedLaunchLookup = false } = opts
    if (!accessToken) return
    try {
      const { res, body } = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/${appId}/package`, {
        headers: {
          'Content-Type': 'application/json',
        },
      })
      if (res.status === 401) {
        return
      }
      if (!res.ok) {
        if (isMappedLaunchLookup && isLtiLaunch && res.status === 404) {
          setBootstrapInfo((prev) => (prev ? { ...prev, app_id: null } : prev))
          setErrorMessage(null)
          return
        }
        setErrorMessage(`Load failed: ${body.error || res.statusText}`)
        return
      }
      setAppPackage(body)
      if (!isLtiLaunch) {
        sessionStorage.setItem('lastAppId', String(body.id))
      }
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

    timerRef.current = setInterval(() => {
      setElapsedTime((prev) => prev + 1)
    }, 1000)

    try {
      const requestBody = {
        prompt,
        generation_mode: generationMode,
      }

      if (generationMode === MODE_STRUCTURED) {
        requestBody.question_type = questionType
      }

      if (isRevising) {
        requestBody.app_id = appPackage.id
        requestBody.preview = true
        requestBody.convert_mode = convertMode
        setOriginalApp(appPackage)
      }

      const { res, body } = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/generate`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(requestBody),
      })
      if (res.status === 401) {
        return
      }
      if (!res.ok) {
        setErrorMessage(body.error || (isRevising ? 'Revision failed' : 'Generation failed'))
        return
      }

      if (isRevising) {
        setPendingRevision(body)
      } else {
        setAppPackage(body)
        if (body.id && !isLtiLaunch) {
          sessionStorage.setItem('lastAppId', String(body.id))
        }
      }
      setPrompt('')
    } catch (e) {
      setErrorMessage(String(e))
    } finally {
      if (timerRef.current) {
        clearInterval(timerRef.current)
        timerRef.current = null
      }
      setGenerating(false)
    }
  }

  async function keepRevision() {
    if (!pendingRevision || !accessToken) return

    const savePayload = pendingRevision.kind === MODE_STRUCTURED
      ? {
          kind: MODE_STRUCTURED,
          schema_version: pendingRevision.schema_version,
          title: pendingRevision.title,
          questions: pendingRevision.questions,
          meta: pendingRevision.meta || {},
          reset_non_instructor_state: resetNonInstructorStateOnSave,
        }
      : {
          kind: MODE_OPEN,
          title: pendingRevision.title,
          html: pendingRevision.html,
          css: pendingRevision.css,
          js: pendingRevision.js,
          reset_non_instructor_state: resetNonInstructorStateOnSave,
        }

    try {
      const { res, body } = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/${pendingRevision.id}/save-revision`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(savePayload),
      })

      if (res.status === 401) {
        return
      }
      if (!res.ok) {
        setErrorMessage(body.error || 'Failed to save revision')
        return
      }

      setAppPackage(pendingRevision)
      setPendingRevision(null)
      setOriginalApp(null)
      setResetNonInstructorStateOnSave(false)
      setErrorMessage(null)

      if (editorOpen) {
        setEditorOpen(false)
      }
    } catch (e) {
      setErrorMessage(`Save error: ${String(e)}`)
    }
  }

  function revertRevision() {
    if (originalApp) {
      setAppPackage(originalApp)
    }

    setPendingRevision(null)
    setOriginalApp(null)
  }

  function updateStructuredRevealSetting(enabled) {
    const source = pendingRevision || appPackage
    if (!source || source.kind !== MODE_STRUCTURED || !Array.isArray(source.questions) || !source.questions[0]) {
      return
    }

    const nextQuestions = [...source.questions]
    nextQuestions[0] = {
      ...nextQuestions[0],
      reveal_correct_after_two_incorrect_attempts: enabled,
    }

    if (pendingRevision && pendingRevision.kind === MODE_STRUCTURED) {
      setPendingRevision({
        ...pendingRevision,
        questions: nextQuestions,
      })
      return
    }

    setOriginalApp(appPackage)
    setPendingRevision({
      ...source,
      questions: nextQuestions,
    })
  }

  async function clearApp() {
    if (!accessToken) return
    setClearing(true)
    setErrorMessage(null)

    try {
      const tokenLti = parseJwt(accessToken)?.lti
      const launchLti = bootstrapInfo?.lti || tokenLti
      const hasMapping = Boolean(launchLti?.issuer && launchLti?.deployment_id && launchLti?.resource_link_id)
      if (hasMapping) {
        const { res, body } = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/mapping`, {
          method: 'DELETE',
          headers: { 'Content-Type': 'application/json' },
        })
        if (res.status === 401) {
          return
        }
        if (!res.ok) {
          setErrorMessage(body.error || 'Failed to clear mapping')
          return
        }
      }

      setAppPackage(null)
      setPrompt('')
      setPendingRevision(null)
      setOriginalApp(null)
      if (!isLtiLaunch) {
        sessionStorage.removeItem('lastAppId')
      }
    } catch (e) {
      setErrorMessage(`Clear error: ${String(e)}`)
    } finally {
      setClearing(false)
    }
  }

  async function insertDeepLinkItem() {
    if (!isDeepLinkLaunch) return
    if (!deepLinkReturnUrl) {
      setErrorMessage('Missing deep_link_return_url claim in deep-link launch.')
      return
    }
    if (!ltiServer || !toolSupportJwt) {
      setErrorMessage('Missing Tool Support context for deep-link insertion.')
      return
    }
    if (!appPackage?.id) {
      setErrorMessage('Generate an activity before inserting into Canvas.')
      return
    }
    if (pendingRevision) {
      setErrorMessage('Keep or revert the pending revision before inserting into Canvas.')
      return
    }

    const body = {
      'https://purl.imsglobal.org/spec/lti-dl/claim/content_items': [
        {
          type: 'ltiResourceLink',
          title: appPackage.title || 'Learning activity',
          text: appPackage.title || 'Learning activity',
          url: targetLinkUri,
          iframe: {
            width: DEEP_LINK_IFRAME_WIDTH,
            height: DEEP_LINK_IFRAME_HEIGHT,
          },
          custom: {
            ox6q_app_id: String(appPackage.id),
          },
        },
      ],
    }

    setInsertingDeepLink(true)
    setErrorMessage(null)

    try {
      const res = await fetch(`${ltiServer}/deep-linking`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${toolSupportJwt}`,
        },
        body: JSON.stringify(body),
      })

      if (!res.ok) {
        const payload = await res.json().catch(() => ({}))
        throw new Error(payload.error || `Deep-link signing failed (${res.status})`)
      }

      const payload = await res.json().catch(() => ({}))
      if (!payload?.jwt) {
        throw new Error('Deep-link signing response did not include jwt.')
      }

      setDeepLinkingJwt(payload.jwt)
    } catch (e) {
      setErrorMessage(`Insert failed: ${String(e.message || e)}`)
    } finally {
      setInsertingDeepLink(false)
    }
  }

  function cancelDeepLinkLaunch() {
    if (launchReturnUrl) {
      window.location.assign(launchReturnUrl)
      return
    }
    window.history.back()
  }

  async function openEditModal() {
    preEditHeightRef.current = Math.round(window.innerHeight)
    if (!appPackage?.id) {
      setResetNonInstructorStateOnSave(false)
      setIsEditorFullScreen(true)
      setEditorOpen(true)
      return
    }
    const { res, body } = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/${appPackage.id}/state-summary`, {
      headers: {
        'Content-Type': 'application/json',
      },
    })

    if (res.status === 401) return
    if (!res.ok) {
      setErrorMessage(body.error || 'Failed to load state summary.')
      return
    }

    if (body.has_non_instructor_state) {
      setStateSummary(body)
      setShowStateResetWarning(true)
      return
    }

    setResetNonInstructorStateOnSave(false)
    setIsEditorFullScreen(true)
    setEditorOpen(true)
  }

  function confirmResetAndEdit() {
    preEditHeightRef.current = Math.round(window.innerHeight)
    setShowStateResetWarning(false)
    setResetNonInstructorStateOnSave(true)
    setIsEditorFullScreen(true)
    setEditorOpen(true)
  }

  function cancelEditWarning() {
    setShowStateResetWarning(false)
    setStateSummary(null)
    setResetNonInstructorStateOnSave(false)
  }

  function closeEditModal() {
    setEditorOpen(false)
    setIsEditorFullScreen(false)
    setShowStateResetWarning(false)
    setStateSummary(null)
    setResetNonInstructorStateOnSave(false)
    setPendingRevision(null)
    setOriginalApp(null)
    setConvertMode(false)
  }

  useEffect(() => {
    if (!accessToken || !bootstrapInfo?.expires_in) return

    const expiresInMs = bootstrapInfo.expires_in * 1000
    const refreshInterval = expiresInMs * 0.95

    const interval = setInterval(async () => {
      await refreshAccessToken(accessToken)
    }, refreshInterval)

    return () => clearInterval(interval)
  }, [accessToken, bootstrapInfo])

  useEffect(() => {
    if (!accessToken) return
    if (appPackage) return

    const mappedAppId = bootstrapInfo?.app_id
    if (mappedAppId) {
      loadAppById(mappedAppId, { isMappedLaunchLookup: true })
      return
    }

    if (isLtiLaunch) return

    const lastAppId = sessionStorage.getItem('lastAppId')
    if (!lastAppId) return
    loadAppById(lastAppId)
  }, [accessToken, appPackage, bootstrapInfo, isLtiLaunch])

  useEffect(() => {
    if (!appPackage) {
      setGenerationMode(MODE_STRUCTURED)
      setQuestionType(DEFAULT_STRUCTURED_TYPE)
      setModeLocked(false)
      setConvertMode(false)
      return
    }

    const mode = appPackage.kind === MODE_STRUCTURED ? MODE_STRUCTURED : MODE_OPEN
    setGenerationMode(mode)
    if (mode === MODE_STRUCTURED) {
      const type = appPackage?.questions?.[0]?.question_type || DEFAULT_STRUCTURED_TYPE
      setQuestionType(type)
    } else {
      setQuestionType(DEFAULT_STRUCTURED_TYPE)
    }
    setModeLocked(true)
    setConvertMode(false)
  }, [appPackage])

  useEffect(() => {
    if (bootstrapInfo) {
      sessionStorage.setItem('bootstrapInfo', JSON.stringify(bootstrapInfo))
      return
    }
    sessionStorage.removeItem('bootstrapInfo')
  }, [bootstrapInfo])

  useEffect(() => {
    if (!ltiServer) {
      sessionStorage.removeItem('ltiServer')
      return
    }
    sessionStorage.setItem('ltiServer', ltiServer)
  }, [ltiServer])

  useEffect(() => {
    if (!toolSupportJwt) {
      sessionStorage.removeItem('toolSupportJwt')
      return
    }
    sessionStorage.setItem('toolSupportJwt', toolSupportJwt)
  }, [toolSupportJwt])

  useEffect(() => {
    if (!accessToken) return
    if (bootstrapInfo?.lti) return
    const payload = parseJwt(accessToken)
    if (!payload || !payload.lti) return
    setBootstrapInfo((prev) => (prev ? { ...prev, lti: payload.lti } : { lti: payload.lti }))
  }, [accessToken, bootstrapInfo])

  useEffect(() => {
    if (pendingRevision) {
      sessionStorage.setItem('pendingRevision', JSON.stringify(pendingRevision))
    } else {
      sessionStorage.removeItem('pendingRevision')
    }
  }, [pendingRevision])

  useEffect(() => {
    return () => {
      if (timerRef.current) {
        clearInterval(timerRef.current)
      }
    }
  }, [])

  useEffect(() => {
    if (!isLtiLaunch) return

    let resizeTimer = null
    let lastHeight = 0

    const getContentHeight = () => {
      const root = contentRootRef.current
      if (root) {
        return Math.max(
          root.scrollHeight || 0,
          root.offsetHeight || 0,
          Math.ceil(root.getBoundingClientRect().height || 0),
        )
      }
      const body = document.body
      const html = document.documentElement
      return Math.max(
        body?.scrollHeight ?? 0,
        body?.offsetHeight ?? 0,
        html?.scrollHeight ?? 0,
        html?.offsetHeight ?? 0,
      )
    }

    const postFrameResize = () => {
      let nextHeight
      if (editorOpen && isEditorFullScreenRef.current) {
        nextHeight = canvasViewportHeightRef.current ?? window.screen?.availHeight ?? 900
      } else if (editorOpen) {
        // Compact edit mode — content is position:fixed so document flow height is near-zero;
        // restore the pre-edit height so observers don't fight the intentional resize
        nextHeight = preEditHeightRef.current ?? EDITOR_COMPACT_HEIGHT
      } else {
        nextHeight = getContentHeight()
      }
      if (!Number.isFinite(nextHeight) || nextHeight <= 0) return

      const height = Math.max(120, Math.ceil(nextHeight))
      if (Math.abs(height - lastHeight) < 2) return
      lastHeight = height

      const payload = { subject: 'lti.frameResize', height }
      // Canvas/LTI postMessage variants
      window.parent.postMessage(payload, '*')
      window.parent.postMessage(JSON.stringify(payload), '*')
      if (window.top && window.top !== window.parent) {
        window.top.postMessage(payload, '*')
        window.top.postMessage(JSON.stringify(payload), '*')
      }
    }

    const scheduleResize = () => {
      if (resizeTimer) clearTimeout(resizeTimer)
      resizeTimer = setTimeout(postFrameResize, 60)
    }

    const resizeObserver = typeof ResizeObserver !== 'undefined'
      ? new ResizeObserver(scheduleResize)
      : null
    resizeObserver?.observe(document.documentElement)
    if (document.body) resizeObserver?.observe(document.body)

    const mutationObserver = new MutationObserver(scheduleResize)
    mutationObserver.observe(document.documentElement, {
      childList: true,
      subtree: true,
      characterData: true,
      attributes: true,
    })

    window.addEventListener('resize', scheduleResize)

    postFrameResize()
    const t1 = setTimeout(postFrameResize, 120)
    const t2 = setTimeout(postFrameResize, 360)
    const t3 = setTimeout(postFrameResize, 1000)

    return () => {
      if (resizeTimer) clearTimeout(resizeTimer)
      clearTimeout(t1)
      clearTimeout(t2)
      clearTimeout(t3)
      window.removeEventListener('resize', scheduleResize)
      mutationObserver.disconnect()
      resizeObserver?.disconnect()
    }
  }, [isLtiLaunch, isResourceLaunch, editorOpen, appPackage?.id, pendingRevision?.id, errorMessage])

  // Intentional resize when edit mode or full-screen state changes — no observer teardown
  useEffect(() => {
    if (!isLtiLaunch || !editorOpen) return
    const height = isEditorFullScreen
      ? (canvasViewportHeight ?? window.screen?.availHeight ?? 900)
      : (preEditHeightRef.current ?? EDITOR_COMPACT_HEIGHT)
    const payload = { subject: 'lti.frameResize', height: Math.ceil(height) }
    window.parent.postMessage(payload, '*')
    window.parent.postMessage(JSON.stringify(payload), '*')
    if (window.top && window.top !== window.parent) {
      window.top.postMessage(payload, '*')
      window.top.postMessage(JSON.stringify(payload), '*')
    }
  }, [isLtiLaunch, editorOpen, isEditorFullScreen, canvasViewportHeight])

  // Set document height synchronously (before MutationObserver fires) so LtiHeightLimit's
  // internal observer measures the correct target height rather than the fixed-overlay content height.
  // Uses `height` (not `min-height`) so that shrinking works: min-height can't override the current
  // viewport height, but an explicit height forces the element to exactly the target value.
  useLayoutEffect(() => {
    if (!isLtiLaunch || !editorOpen) {
      document.documentElement.style.height = ''
      return
    }
    const height = isEditorFullScreen
      ? (canvasViewportHeight ?? window.screen?.availHeight ?? 900)
      : (preEditHeightRef.current ?? EDITOR_COMPACT_HEIGHT)
    document.documentElement.style.height = `${Math.ceil(height)}px`
    return () => {
      document.documentElement.style.height = ''
    }
  }, [isLtiLaunch, editorOpen, isEditorFullScreen, canvasViewportHeight])

  // Auto-focus close button when editor opens — standard accessible modal pattern
  useEffect(() => {
    if (!editorOpen) return
    const timer = setTimeout(() => {
      closeButtonContainerRef.current?.querySelector('button, [role="button"]')?.focus()
    }, 50)
    return () => clearTimeout(timer)
  }, [editorOpen])

  const activePackage = pendingRevision || appPackage

  function renderActivePackage() {
    if (activePackage?.kind === MODE_STRUCTURED) {
      return <StructuredRunnerFrame pkg={activePackage} />
    }
    return (
      <Runner
        apiBase={API_BASE}
        token={accessToken}
        pkg={activePackage}
        onError={setErrorMessage}
      />
    )
  }

  function renderAuthoringDrawer(mode) {
    const isDeepLinkMode = mode === 'deep-link'
    const isEditMode = mode === 'edit'
    const drawerMinHeight = isEditMode
      ? '100%'
      : (isDeepLinkMode ? `${DEEP_LINK_PREVIEW_MIN_HEIGHT}px` : '100vh')
    const contentPadding = isEditMode ? 'none' : 'small'
    const previewMinHeight = isDeepLinkMode ? `${DEEP_LINK_PREVIEW_MIN_HEIGHT}px` : undefined

    return (
      <DrawerLayout minHeight={drawerMinHeight} style={{ width: '100%', height: isEditMode ? '100%' : undefined }}>
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

            {isDeepLinkMode && (
              <Alert variant="info" margin="0 0 small 0">
                Generate a new activity and insert it into the Canvas page.
              </Alert>
            )}

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

            <View as="div" margin="small 0">
              <View as="div" margin="0 0 small 0">
                <Text size="small" weight="bold">Generation mode</Text>
                <Flex gap="small" margin="x-small 0 0 0">
                  <label style={{ display: 'inline-flex', gap: '0.35rem', alignItems: 'center' }}>
                    <input
                      type="radio"
                      name="generation_mode"
                      value={MODE_STRUCTURED}
                      checked={generationMode === MODE_STRUCTURED}
                      disabled={modeLocked && !convertMode}
                      onChange={() => setGenerationMode(MODE_STRUCTURED)}
                    />
                    <span>Standard question type</span>
                  </label>
                  <label style={{ display: 'inline-flex', gap: '0.35rem', alignItems: 'center' }}>
                    <input
                      type="radio"
                      name="generation_mode"
                      value={MODE_OPEN}
                      checked={generationMode === MODE_OPEN}
                      disabled={modeLocked && !convertMode}
                      onChange={() => setGenerationMode(MODE_OPEN)}
                    />
                    <span>Open interaction</span>
                  </label>
                </Flex>
              </View>

              {generationMode === MODE_STRUCTURED && (
                <View as="div" margin="0 0 small 0">
                  <label style={{ display: 'flex', flexDirection: 'column', gap: '0.35rem' }}>
                    <Text size="small" weight="bold">Question type</Text>
                    <select
                      value={questionType}
                      disabled={modeLocked && !convertMode}
                      onChange={(e) => setQuestionType(e.target.value)}
                    >
                      <option value="multiple_choice_single_answer">Multiple choice (single answer)</option>
                      <option value="multiple_choice_multiple_answer">Multiple choice (multiple answer)</option>
                      <option value="matching">Matching</option>
                      <option value="fill_in_blank">Fill in the blank</option>
                      <option value="ordering">Ordering</option>
                      <option value="numeric">Numeric</option>
                    </select>
                  </label>
                </View>
              )}

              {(pendingRevision || appPackage)?.kind === MODE_STRUCTURED && (
                <View as="div" margin="0 0 small 0">
                  <label style={{ display: 'inline-flex', gap: '0.35rem', alignItems: 'center' }}>
                    <input
                      type="checkbox"
                      checked={(pendingRevision || appPackage)?.questions?.[0]?.reveal_correct_after_two_incorrect_attempts !== false}
                      onChange={(e) => updateStructuredRevealSetting(e.target.checked)}
                      disabled={generating}
                    />
                    <span>Show correct answer after 2 incorrect attempts</span>
                  </label>
                </View>
              )}

              {appPackage && modeLocked && (
                <View as="div" margin="0 0 small 0">
                  <label style={{ display: 'inline-flex', gap: '0.35rem', alignItems: 'center' }}>
                    <input
                      type="checkbox"
                      checked={convertMode}
                      onChange={(e) => {
                        const checked = e.target.checked
                        setConvertMode(checked)
                        if (!checked) {
                          const modeValue = appPackage?.kind === MODE_STRUCTURED ? MODE_STRUCTURED : MODE_OPEN
                          setGenerationMode(modeValue)
                          if (modeValue === MODE_STRUCTURED) {
                            setQuestionType(appPackage?.questions?.[0]?.question_type || DEFAULT_STRUCTURED_TYPE)
                          } else {
                            setQuestionType(DEFAULT_STRUCTURED_TYPE)
                          }
                        }
                      }}
                      disabled={generating || pendingRevision}
                    />
                    <span>Convert type/mode for this revision</span>
                  </label>
                </View>
              )}

              <TextArea
                label={<ScreenReaderContent>App description</ScreenReaderContent>}
                placeholder={
                  appPackage
                    ? 'What changes would you like to make?…'
                    : 'Describe the learning activity you want…'
                }
                value={prompt}
                onChange={(e) => setPrompt(e.target.value)}
                disabled={generating}
                height="100px"
              />

              <Flex gap="small" margin="small 0">
                <Button
                  onClick={generateApp}
                  disabled={generating || !prompt.trim() || !accessToken || pendingRevision || (generationMode === MODE_STRUCTURED && !questionType)}
                  color="primary"
                >
                  {generating
                    ? (appPackage ? 'Revising…' : 'Generating…')
                    : (appPackage ? 'Revise app' : 'Generate app')
                  }
                </Button>

                {appPackage && !isEditMode && (
                  <Button
                    onClick={clearApp}
                    disabled={clearing || generating}
                  >
                    {clearing ? 'Clearing…' : 'Start Over'}
                  </Button>
                )}

              </Flex>

              {isDeepLinkMode && (
                <Flex gap="small" margin="small 0">
                  <Button
                    onClick={insertDeepLinkItem}
                    disabled={insertingDeepLink || generating || pendingRevision || !appPackage?.id}
                    color="primary"
                  >
                    {insertingDeepLink ? 'Inserting…' : 'Insert into Canvas'}
                  </Button>
                  <Button
                    onClick={cancelDeepLinkLaunch}
                    disabled={insertingDeepLink || generating}
                  >
                    Cancel
                  </Button>
                </Flex>
              )}
            </View>

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
          <View
            as="div"
            padding={contentPadding}
            style={{
              height: isEditMode ? '100%' : undefined,
              minHeight: previewMinHeight,
              boxSizing: 'border-box',
              width: '100%',
            }}
          >
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
            {renderActivePackage()}
          </View>
        </DrawerLayout.Content>
      </DrawerLayout>
    )
  }

  const content = (
    <div ref={contentRootRef}>
      {isResourceLaunch && !editorOpen ? (
        <View as="div" padding="small">
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
          <div style={{ position: 'relative' }}>
            {isInstructor && (
              <div
                style={{
                  position: 'absolute',
                  top: '0.5rem',
                  right: '0.5rem',
                  zIndex: 20,
                }}
              >
                <Button color="primary" onClick={openEditModal}>
                  {appPackage?.id ? 'Edit' : 'Create'}
                </Button>
              </div>
            )}
            {renderActivePackage()}
          </div>
        </View>
      ) : !isResourceLaunch ? (
        renderAuthoringDrawer(isDeepLinkLaunch ? 'deep-link' : 'default')
      ) : null}

      {showStateResetWarning && (
        <View
          as="div"
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(0, 0, 0, 0.35)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 2000,
            padding: '1rem',
          }}
        >
          <View as="div" background="primary" padding="large" borderRadius="medium" style={{ maxWidth: '560px', width: '100%' }}>
            <Flex direction="column" gap="small">
              <Heading level="h3">Reset Student Data?</Heading>
              <Text>
                Editing this activity will reset saved learner state for this embedded instance.
              </Text>
              {stateSummary && (
                <Text color="secondary">
                  Non-instructor state records: {stateSummary.non_instructor_state_count} (total: {stateSummary.total_state_count})
                </Text>
              )}
              <Flex gap="small">
                <Button color="danger" onClick={confirmResetAndEdit}>Continue and Reset Learner Data</Button>
                <Button onClick={cancelEditWarning}>Cancel</Button>
              </Flex>
            </Flex>
          </View>
        </View>
      )}

      {isResourceLaunch && editorOpen && (
        <div
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(0, 0, 0, 0.35)',
            zIndex: 1500,
            padding: '1rem',
            boxSizing: 'border-box',
          }}
        >
          <div
            style={{
              position: 'relative',
              width: '100%',
              height: '100%',
              background: '#fff',
              borderRadius: '8px',
              overflow: 'hidden',
              display: 'flex',
              flexDirection: 'column',
              boxSizing: 'border-box',
            }}
          >
            {/* Floating close button — top right */}
            <div ref={closeButtonContainerRef} style={{ position: 'absolute', top: '0.5rem', right: '0.5rem', zIndex: 10 }}>
              <IconButton
                screenReaderLabel="Close editor"
                onClick={closeEditModal}
                disabled={generating}
                withBackground={true}
                withBorder={true}
                size="small"
              >
                <IconXLine />
              </IconButton>
            </div>

            {/* Floating full-screen toggle — bottom right */}
            <div style={{ position: 'absolute', bottom: '0.5rem', right: '0.5rem', zIndex: 10 }}>
              <IconButton
                screenReaderLabel={isEditorFullScreen ? 'Exit full screen' : 'Full screen'}
                onClick={() => setIsEditorFullScreen((prev) => !prev)}
                withBackground={true}
                withBorder={true}
                size="small"
              >
                {isEditorFullScreen ? <IconExitFullScreenLine /> : <IconFullScreenLine />}
              </IconButton>
            </div>

            {renderAuthoringDrawer('edit')}
          </div>
        </div>
      )}

      {isDeepLinkLaunch && (
        <DeepLinkForm deepLinkReturnUrl={deepLinkReturnUrl} deepLinkingJwt={deepLinkingJwt} />
      )}
    </div>
  )

  if (isLtiLaunch) {
    return (
      <LtiPageSettings>
        <LtiHeightLimit>
          {!accessToken ? (
            <LtiTokenRetriever handleJwt={handleLtiJwt}>
              {content}
            </LtiTokenRetriever>
          ) : content}
        </LtiHeightLimit>
      </LtiPageSettings>
    )
  }

  return content
}
