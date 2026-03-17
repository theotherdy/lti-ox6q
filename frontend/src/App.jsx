import { useState, useEffect, useLayoutEffect, useRef, useCallback } from 'react'
import { LtiTokenRetriever, LtiPageSettings, LtiHeightLimit } from '@oxctl/ui-lti'
import Runner from './components/Runner'
import StructuredRunnerFrame from './components/StructuredRunnerFrame'
import DeepLinkForm from './components/DeepLinkForm'
import { View } from '@instructure/ui-view'
import { Flex } from '@instructure/ui-flex'
import { Button, IconButton } from '@instructure/ui-buttons'
import { TextArea } from '@instructure/ui-text-area'
import { TextInput } from '@instructure/ui-text-input'
import { Alert } from '@instructure/ui-alerts'
import { DrawerLayout } from '@instructure/ui-drawer-layout'
import { Heading } from '@instructure/ui-heading'
import { Text } from '@instructure/ui-text'
import { Spinner } from '@instructure/ui-spinner'
import { ToggleDetails } from '@instructure/ui-toggle-details'
import { RadioInputGroup, RadioInput } from '@instructure/ui-radio-input'
import { SimpleSelect } from '@instructure/ui-simple-select'
import { Checkbox } from '@instructure/ui-checkbox'
import { IconHamburgerLine, IconXLine, IconFullScreenLine, IconExitFullScreenLine, IconRefreshLine } from '@instructure/ui-icons'
import { ScreenReaderContent } from '@instructure/ui-a11y-content'

function resolveApiBase() {
  const configuredRaw = String(import.meta.env.VITE_API_BASE_URL || '').trim()
  const viteBaseRaw = String(import.meta.env.BASE_URL || '/').trim() || '/'
  const viteBase = viteBaseRaw.endsWith('/') ? viteBaseRaw.slice(0, -1) : viteBaseRaw

  if (configuredRaw) {
    // Accept values like "example.com/ox6q" by coercing to HTTPS in production-style setups.
    const looksLikeHostWithoutScheme = /^[a-z0-9.-]+\.[a-z]{2,}(?::\d+)?(\/|$)/i.test(configuredRaw)
    const configured = looksLikeHostWithoutScheme ? `https://${configuredRaw}` : configuredRaw
    try {
      return new URL(configured, window.location.origin).href.replace(/\/$/, '')
    } catch {
      // Fall through to environment-aware defaults.
    }
  }

  const isLocalHost = ['localhost', '127.0.0.1'].includes(window.location.hostname)
  if (isLocalHost) {
    return 'http://localhost:8000'
  }

  return `${window.location.origin}${viteBase}`.replace(/\/$/, '')
}

const API_BASE = resolveApiBase()
const MODE_STRUCTURED = 'structured_question_set'
const MODE_OPEN = 'open_interaction'
const DEFAULT_STRUCTURED_TYPE = 'multiple_choice_single_answer'
const DEEP_LINK_IFRAME_WIDTH = 1000
const DEEP_LINK_IFRAME_HEIGHT = 520
const DEEP_LINK_PREVIEW_MIN_HEIGHT = 560
const EDITOR_COMPACT_HEIGHT = 600
const VISION_MODE_AUTO = 'auto'
const VISION_MODE_FORCE = 'force'
const VISION_MODE_OFF = 'off'

const RIGHTS_BASIS_OPTIONS = [
  { value: 'copyright_holder', label: 'I hold the copyright' },
  { value: 'permission_obtained', label: 'I have obtained permission to use this file' },
  { value: 'public_domain', label: 'Public domain' },
  { value: 'instructional_exception', label: 'Image is subject to an exception (e.g., illustration for instruction)' },
  { value: 'creative_commons', label: 'Creative Commons' },
]

const CC_LICENSE_OPTIONS = [
  { value: 'cc_by', label: 'CC BY' },
  { value: 'cc_by_sa', label: 'CC BY-SA' },
  { value: 'cc_by_nd', label: 'CC BY-ND' },
  { value: 'cc_by_nc', label: 'CC BY-NC' },
  { value: 'cc_by_nc_sa', label: 'CC BY-NC-SA' },
  { value: 'cc_by_nc_nd', label: 'CC BY-NC-ND' },
  { value: 'cc0', label: 'CC0' },
]

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

function detectGeneratedFromPackage(pkg) {
  if (!pkg || typeof pkg !== 'object') return false
  if (pkg.lifecycle_status === 'inserted') return true
  if (pkg.kind === 'structured_question_set') {
    return Array.isArray(pkg.questions) && pkg.questions.length > 0
  }
  if (pkg.kind === 'open_interaction') {
    const html = String(pkg.html ?? '').trim()
    const css = String(pkg.css ?? '').trim()
    const js = String(pkg.js ?? '').trim()
    return css !== '' || js !== '' || (html !== '' && html !== "<div id='app'></div>")
  }
  return false
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

  const [previousApp, setPreviousApp] = useState(() => {
    if (isLtiLaunch) return null
    const raw = sessionStorage.getItem('previousApp')
    if (!raw) return null
    try {
      return JSON.parse(raw)
    } catch {
      return null
    }
  })
  const [savedApp, setSavedApp] = useState(() => {
    if (isLtiLaunch) return null
    const raw = sessionStorage.getItem('savedApp')
    if (!raw) return null
    try {
      return JSON.parse(raw)
    } catch {
      return null
    }
  })

  const [isTrayOpen, setIsTrayOpen] = useState(true)
  const [generationMode, setGenerationMode] = useState(MODE_STRUCTURED)
  const [questionType, setQuestionType] = useState(DEFAULT_STRUCTURED_TYPE)
  const [visionMode, setVisionMode] = useState(VISION_MODE_AUTO)
  const [showStartAgainConfirm, setShowStartAgainConfirm] = useState(false)
  const [hasGeneratedApp, setHasGeneratedApp] = useState(false)
  const [lastRevisionSummary, setLastRevisionSummary] = useState(null)
  const [isImagePanelOpen, setIsImagePanelOpen] = useState(false)
  const [assets, setAssets] = useState([])
  const [assetsLoading, setAssetsLoading] = useState(false)
  const [uploadingAsset, setUploadingAsset] = useState(false)
  const [deletingAssetId, setDeletingAssetId] = useState(null)
  const [pendingAssetDeletes, setPendingAssetDeletes] = useState([])
  const [assetLabel, setAssetLabel] = useState('')
  const [assetAlt, setAssetAlt] = useState('')
  const [assetRightsBasis, setAssetRightsBasis] = useState('')
  const [assetCcLicense, setAssetCcLicense] = useState('')
  const [assetCopyrightHolder, setAssetCopyrightHolder] = useState('')
  const [assetRightsNote, setAssetRightsNote] = useState('')
  const [assetFile, setAssetFile] = useState(null)
  const [showAdvancedControls, setShowAdvancedControls] = useState(false)

  const [deepLinkingJwt, setDeepLinkingJwt] = useState('')
  const [insertingDeepLink, setInsertingDeepLink] = useState(false)
  const [savingForInsert, setSavingForInsert] = useState(false)

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
  const creatingDraftRef = useRef(false)
  // Keep refs in sync on every render so effect closures always read current values
  isEditorFullScreenRef.current = isEditorFullScreen
  canvasViewportHeightRef.current = canvasViewportHeight

  useEffect(() => {
    if (!isLtiLaunch) return
    sessionStorage.removeItem('accessToken')
    sessionStorage.removeItem('bootstrapInfo')
    sessionStorage.removeItem('toolSupportJwt')
    sessionStorage.removeItem('ltiServer')
    sessionStorage.removeItem('previousApp')
    sessionStorage.removeItem('savedApp')
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
  const canManageAssets = !isLtiLaunch || isInstructor

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
      setSavedApp(body)
      setPreviousApp(null)
      setHasGeneratedApp(detectGeneratedFromPackage(body))
      setPendingAssetDeletes([])
      setIsImagePanelOpen(false)
      if (!isLtiLaunch) {
        sessionStorage.setItem('lastAppId', String(body.id))
      }
      setErrorMessage(null)
    } catch (e) {
      setErrorMessage(`Load error: ${String(e)}`)
    }
  }

  async function createDraftApp() {
    if (!accessToken || creatingDraftRef.current || appPackage?.id) return
    creatingDraftRef.current = true
    try {
      const { res, body } = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/draft`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ title: 'Draft activity' }),
      })
      if (res.status === 401) return
      if (!res.ok) {
        setErrorMessage(body.error || 'Failed to create draft app')
        return
      }
      setAppPackage(body)
      setSavedApp(body)
      setPreviousApp(null)
      setHasGeneratedApp(false)
      setPendingAssetDeletes([])
      setIsImagePanelOpen(false)
      if (!isLtiLaunch) {
        sessionStorage.setItem('lastAppId', String(body.id))
      }
      setErrorMessage(null)
    } catch (e) {
      setErrorMessage(`Draft creation error: ${String(e)}`)
    } finally {
      creatingDraftRef.current = false
    }
  }

  async function deleteDraftIfApplicable(pkg = appPackage) {
    if (!pkg?.id) return true
    if (pkg.lifecycle_status !== 'draft_uninserted') return true

    const { res, body } = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/${pkg.id}/draft`, {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json',
      },
    })
    if (res.status === 401) return false
    if (!res.ok) {
      setErrorMessage(body.error || 'Failed to delete draft')
      return false
    }
    return true
  }

  async function markCurrentAppInserted() {
    if (!appPackage?.id) return
    const { res } = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/${appPackage.id}/mark-inserted`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
    })
    if (res.status === 401) return
    if (res.ok) {
      setAppPackage((prev) => (prev ? { ...prev, lifecycle_status: 'inserted', inserted_at: new Date().toISOString() } : prev))
    }
  }

  async function generateApp() {
    if (!prompt.trim() || !accessToken) return

    const hasAppId = Boolean(appPackage?.id)
    const isRevising = Boolean(hasGeneratedApp && hasAppId)

    if (generationMode === MODE_STRUCTURED && questionType === 'image_hotspot_single' && assets.length < 1) {
      setErrorMessage('Upload at least one image before generating an image hotspot question.')
      return
    }

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
        vision_mode: visionMode,
      }

      if (generationMode === MODE_STRUCTURED) {
        requestBody.question_type = questionType
      }

      if (hasAppId) {
        requestBody.app_id = appPackage.id
      }

      if (isRevising) {
        requestBody.preview = true
        if (appPackage?.kind === MODE_OPEN) {
          requestBody.base_package = {
            kind: MODE_OPEN,
            title: appPackage.title,
            html: appPackage.html,
            css: appPackage.css,
            js: appPackage.js,
            source_jsx: appPackage.source_jsx,
          }
        } else if (appPackage?.kind === MODE_STRUCTURED) {
          requestBody.base_package = {
            kind: MODE_STRUCTURED,
            title: appPackage.title,
            schema_version: appPackage.schema_version,
            questions: appPackage.questions,
            meta: appPackage.meta,
          }
        }
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
        setErrorMessage(body?.error || (isRevising ? 'Revision failed — please try again.' : 'Generation failed — please try again.'))
        return
      }

      if (isRevising) {
        setPreviousApp(appPackage)
        setAppPackage(body)
        setHasGeneratedApp(true)
        if (body?.change_summary && typeof body.change_summary === 'object') {
          setLastRevisionSummary({
            changeSummary: body.change_summary,
            humanSummary: Array.isArray(body.human_change_summary) ? body.human_change_summary : [],
            autoRetry: Boolean(body.auto_retry),
            noOpRevisionDetected: Boolean(body.no_op_revision_detected),
          })
        } else {
          setLastRevisionSummary(null)
        }
      } else {
        setAppPackage(body)
        setSavedApp(body)
        setPreviousApp(null)
        setHasGeneratedApp(true)
        setLastRevisionSummary(null)
        setPendingAssetDeletes([])
        if (body.id && !isLtiLaunch) {
          sessionStorage.setItem('lastAppId', String(body.id))
        }
      }
      setPrompt('')
    } catch (e) {
      setErrorMessage(isRevising ? 'Revision failed — please try again.' : 'Generation failed — please try again.')
    } finally {
      if (timerRef.current) {
        clearInterval(timerRef.current)
        timerRef.current = null
      }
      setGenerating(false)
    }
  }

  async function saveCurrentRevision() {
    if (!appPackage || !accessToken) return false

    const savePayload = appPackage.kind === MODE_STRUCTURED
      ? {
          kind: MODE_STRUCTURED,
          schema_version: appPackage.schema_version,
          title: appPackage.title,
          questions: appPackage.questions,
          meta: appPackage.meta || {},
          reset_non_instructor_state: resetNonInstructorStateOnSave,
        }
      : {
          kind: MODE_OPEN,
          title: appPackage.title,
          html: appPackage.html,
          css: appPackage.css,
          js: appPackage.js,
          source_jsx: appPackage.source_jsx || appPackage.js,
          reset_non_instructor_state: resetNonInstructorStateOnSave,
        }

    try {
      const { res, body } = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/${appPackage.id}/save-revision`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(savePayload),
      })

      if (res.status === 401) {
        return false
      }
      if (!res.ok) {
        setErrorMessage(body.error || 'Failed to save revision')
        return false
      }

      if (appPackage?.id && pendingAssetDeletes.length > 0) {
        const deleteIds = Array.from(new Set(pendingAssetDeletes))
        let failedDeletes = 0
        for (const assetId of deleteIds) {
          const del = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/${appPackage.id}/assets/${assetId}`, {
            method: 'DELETE',
            headers: {
              'Content-Type': 'application/json',
            },
          })
          if (!del.res.ok && del.res.status !== 401 && del.res.status !== 404) {
            failedDeletes += 1
          }
        }
        setPendingAssetDeletes([])
        await loadAssets(appPackage.id)
        if (failedDeletes > 0) {
          setErrorMessage(`Saved revision, but ${failedDeletes} image delete request(s) failed.`)
        }
      }

      setSavedApp(appPackage)
      setPreviousApp(null)
      setResetNonInstructorStateOnSave(false)
      if (pendingAssetDeletes.length === 0) {
        setErrorMessage(null)
      }

      if (editorOpen) {
        closeEditModal()
      }
      return true
    } catch (e) {
      setErrorMessage(`Save error: ${String(e)}`)
      return false
    }
  }

  async function loadAssets(appId = appPackage?.id) {
    if (!appId || !accessToken || !canManageAssets) {
      setAssets([])
      return
    }

    setAssetsLoading(true)
    try {
      const { res, body } = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/${appId}/assets`, {
        headers: {
          'Content-Type': 'application/json',
        },
      })
      if (res.status === 401) return
      if (!res.ok) {
        setErrorMessage(body.error || 'Failed to load app images')
        return
      }
      setAssets(Array.isArray(body.assets) ? body.assets : [])
    } catch (e) {
      setErrorMessage(`Image list error: ${String(e)}`)
    } finally {
      setAssetsLoading(false)
    }
  }

  async function uploadAssetImage() {
    if (!appPackage?.id || !assetFile) return
    if (!assetRightsBasis) {
      setErrorMessage('Select a copyright basis before uploading.')
      return
    }
    if (assetRightsBasis === 'creative_commons' && !assetCcLicense) {
      setErrorMessage('Select a Creative Commons license subtype.')
      return
    }

    const form = new FormData()
    form.append('file', assetFile)
    form.append('rights_basis', assetRightsBasis)
    if (assetLabel.trim()) form.append('label', assetLabel.trim())
    if (assetAlt.trim()) form.append('alt', assetAlt.trim())
    if (assetCopyrightHolder.trim()) form.append('copyright_holder', assetCopyrightHolder.trim())
    if (assetRightsNote.trim()) form.append('rights_note', assetRightsNote.trim())
    if (assetRightsBasis === 'creative_commons') form.append('cc_license', assetCcLicense)

    setUploadingAsset(true)
    try {
      const { res, body } = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/${appPackage.id}/assets/image`, {
        method: 'POST',
        body: form,
      })
      if (res.status === 401) return
      if (!res.ok) {
        setErrorMessage(body.error || 'Image upload failed')
        return
      }

      setAssetFile(null)
      setAssetLabel('')
      setAssetAlt('')
      setAssetRightsBasis('')
      setAssetCcLicense('')
      setAssetCopyrightHolder('')
      setAssetRightsNote('')
      setIsImagePanelOpen(false)
      setErrorMessage(null)
      await loadAssets(appPackage.id)
    } catch (e) {
      setErrorMessage(`Image upload error: ${String(e)}`)
    } finally {
      setUploadingAsset(false)
    }
  }

  async function deleteAssetImage(assetId, stageOnly = false) {
    if (!appPackage?.id || !assetId) return
    if (stageOnly) {
      setAssets((xs) => xs.filter((x) => x.id !== assetId))
      setPendingAssetDeletes((ids) => (ids.includes(assetId) ? ids : [...ids, assetId]))
      setErrorMessage(null)
      return
    }
    setDeletingAssetId(assetId)
    try {
      const { res, body } = await fetchJsonWithAutoRefresh(`${API_BASE}/api/apps/${appPackage.id}/assets/${assetId}`, {
        method: 'DELETE',
        headers: {
          'Content-Type': 'application/json',
        },
      })
      if (res.status === 401) return
      if (!res.ok) {
        setErrorMessage(body.error || 'Failed to delete image')
        return
      }

      setAssets((xs) => xs.filter((x) => x.id !== assetId))
      setErrorMessage(null)
    } catch (e) {
      setErrorMessage(`Image delete error: ${String(e)}`)
    } finally {
      setDeletingAssetId(null)
    }
  }

  function undoRevision() {
    if (previousApp) {
      setAppPackage(previousApp)
    }
    setPreviousApp(null)
    setLastRevisionSummary(null)
  }

  async function cancelEditSession() {
    const restoreAppId = savedApp?.id || appPackage?.id || null
    setAppPackage(savedApp)
    setHasGeneratedApp(detectGeneratedFromPackage(savedApp))
    setSavedApp(null)
    setPreviousApp(null)
    setLastRevisionSummary(null)
    setPendingAssetDeletes([])
    closeEditModal()
    if (restoreAppId) {
      await loadAssets(restoreAppId)
    }
  }

  function updateStructuredRevealSetting(enabled) {
    if (!appPackage || appPackage.kind !== MODE_STRUCTURED || !Array.isArray(appPackage.questions) || !appPackage.questions[0]) {
      return
    }

    const nextQuestions = [...appPackage.questions]
    nextQuestions[0] = {
      ...nextQuestions[0],
      reveal_correct_after_two_incorrect_attempts: enabled,
    }

    setPreviousApp(appPackage)
    setAppPackage({
      ...appPackage,
      questions: nextQuestions,
    })
  }

  async function startAgain() {
    if (appPackage?.id) {
      const deleted = await deleteDraftIfApplicable(appPackage)
      if (!deleted) return
    }
    setAppPackage(null)
    setPrompt('')
    setPreviousApp(null)
    setSavedApp(null)
    setLastRevisionSummary(null)
    setHasGeneratedApp(false)
    setPendingAssetDeletes([])
    setAssets([])
    setIsImagePanelOpen(false)
    setShowStartAgainConfirm(false)
    if (!isLtiLaunch) {
      sessionStorage.removeItem('lastAppId')
    }
  }

  async function clearApp() {
    if (!accessToken) return
    setClearing(true)
    setErrorMessage(null)

    try {
      if (appPackage?.id) {
        const deleted = await deleteDraftIfApplicable(appPackage)
        if (!deleted) return
      }

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
      setPreviousApp(null)
      setSavedApp(null)
      setLastRevisionSummary(null)
      setHasGeneratedApp(false)
      setPendingAssetDeletes([])
      setShowStartAgainConfirm(false)
      setIsImagePanelOpen(false)
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
    const candidateCourseIds = [
      lti?.custom?.canvas_course_id,
      lti?.canvas_course_id,
      lti?.context?.id,
    ]
      .map((value) => String(value ?? '').trim())
      .filter((value) => value !== '')

    // Some Canvas deep-link launches don't include a numeric course id claim.
    // Only fail closed when a course id claim is present but unusable.
    if (candidateCourseIds.length > 0) {
      const hasValidCourseId = candidateCourseIds.some((value) => {
        const parsed = Number.parseInt(value, 10)
        return Number.isFinite(parsed) && parsed > 0
      })
      if (!hasValidCourseId) {
        setErrorMessage('Missing or invalid course context for deep-link insertion.')
        return
      }
    }
    if (!ltiServer || !toolSupportJwt) {
      setErrorMessage('Missing Tool Support context for deep-link insertion.')
      return
    }
    if (!appPackage?.id) {
      setErrorMessage('Generate an activity before inserting into Canvas.')
      return
    }

    if (hasUnsavedChanges) {
      setSavingForInsert(true)
      const saved = await saveCurrentRevision()
      setSavingForInsert(false)
      if (!saved) return
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

      await markCurrentAppInserted()
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
      setSavedApp(appPackage)
      setPreviousApp(null)
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

    setSavedApp(appPackage)
    setPreviousApp(null)
    setResetNonInstructorStateOnSave(false)
    setIsEditorFullScreen(true)
    setEditorOpen(true)
  }

  function confirmResetAndEdit() {
    preEditHeightRef.current = Math.round(window.innerHeight)
    setShowStateResetWarning(false)
    setSavedApp(appPackage)
    setPreviousApp(null)
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
    setPreviousApp(null)
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

    if (!isLtiLaunch) {
      const lastAppId = sessionStorage.getItem('lastAppId')
      if (lastAppId) {
        loadAppById(lastAppId)
        return
      }
      createDraftApp()
      return
    }

    if (isInstructor) {
      createDraftApp()
    }
  }, [accessToken, appPackage, bootstrapInfo, isLtiLaunch, isInstructor])

  useEffect(() => {
    if (!appPackage?.id || !accessToken || !canManageAssets) {
      setAssets([])
      return
    }
    loadAssets(appPackage.id)
  }, [appPackage?.id, accessToken, canManageAssets])

  useEffect(() => {
    if (!appPackage || !hasGeneratedApp) {
      setGenerationMode(MODE_STRUCTURED)
      setQuestionType(DEFAULT_STRUCTURED_TYPE)
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
  }, [appPackage, hasGeneratedApp])

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
    if (previousApp) {
      sessionStorage.setItem('previousApp', JSON.stringify(previousApp))
    } else {
      sessionStorage.removeItem('previousApp')
    }
  }, [previousApp])

  useEffect(() => {
    if (savedApp) {
      sessionStorage.setItem('savedApp', JSON.stringify(savedApp))
    } else {
      sessionStorage.removeItem('savedApp')
    }
  }, [savedApp])

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
  }, [isLtiLaunch, isResourceLaunch, editorOpen, appPackage?.id, errorMessage])

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

  const hasUnsavedChanges = savedApp !== null && appPackage !== savedApp

  function renderActivePackage() {
    if (appPackage?.kind === MODE_STRUCTURED) {
      return <StructuredRunnerFrame pkg={appPackage} />
    }
    return (
      <Runner
        apiBase={API_BASE}
        token={accessToken}
        pkg={appPackage}
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

    function renderRevisionChangeSummary() {
      if (!lastRevisionSummary?.changeSummary) return null

      const summary = lastRevisionSummary.changeSummary
      const changed = Boolean(summary.changed)
      const changedSections = Array.isArray(summary.changed_sections) ? summary.changed_sections : []
      const sectionLabels = {
        title: 'Title',
        html: 'HTML',
        css: 'CSS',
        js: 'JS',
      }

      return (
        <View as="div" margin="0 0 small 0" padding="small" borderWidth="small" borderRadius="medium" background="secondary">
          <Flex direction="column" gap="x-small">
            <Flex justifyItems="space-between" alignItems="center">
              <Text size="small" weight="bold">What changed</Text>
              <View
                as="span"
                padding="xxx-small xx-small"
                borderWidth="small"
                borderRadius="pill"
                style={{ backgroundColor: changed ? '#d1f3df' : '#fff3d0' }}
              >
                <Text size="x-small" weight="bold">{changed ? 'Changed' : 'No meaningful change'}</Text>
              </View>
            </Flex>

            {changedSections.length > 0 ? (
              <Flex gap="x-small" style={{ flexWrap: 'wrap' }}>
                {changedSections.map((section) => (
                  <View key={section} as="span" padding="xxx-small xx-small" borderWidth="small" borderRadius="pill">
                    <Text size="x-small">{sectionLabels[section] || section}</Text>
                  </View>
                ))}
              </Flex>
            ) : (
              <Text size="small" color="secondary">Try a more specific request to force clearer changes.</Text>
            )}

            {Array.isArray(lastRevisionSummary.humanSummary) && lastRevisionSummary.humanSummary.length > 0 && (
              <View as="ul" margin="0" padding="0 0 0 medium">
                {lastRevisionSummary.humanSummary.slice(0, 3).map((line, index) => (
                  <li key={`${index}-${line}`}>
                    <Text size="small">{line}</Text>
                  </li>
                ))}
              </View>
            )}

            {lastRevisionSummary.autoRetry && (
              <Text size="x-small" color="secondary">Automatic correction was applied.</Text>
            )}
          </Flex>
        </View>
      )
    }

    return (
      <DrawerLayout minHeight={drawerMinHeight} style={{ width: '100%', height: isEditMode ? '100%' : undefined }}>
        <DrawerLayout.Tray
          label="Controls"
          open={isTrayOpen}
          placement="start"
          onDismiss={() => setIsTrayOpen(false)}
        >
          <View as="div" padding="medium">
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

            {showStartAgainConfirm && (
              <Alert variant="warning" margin="small 0">
                <View as="div">
                  <Text>Start again? The current app will remain available to students until you save a new one.</Text>
                  <View as="div" margin="x-small 0 0 0">
                    <Button color="danger" margin="0 x-small 0 0" onClick={startAgain}>
                      Yes, start again
                    </Button>
                    <Button onClick={() => setShowStartAgainConfirm(false)}>
                      Cancel
                    </Button>
                  </View>
                </View>
              </Alert>
            )}

            {renderRevisionChangeSummary()}

            <View as="div" margin="small 0">
              <View as="div" margin="0 0 small 0">
                <Flex justifyItems="space-between" alignItems="center" margin="0 0 x-small 0">
                  <Text size="medium" weight="bold">Generation mode</Text>
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
                <RadioInputGroup
                  name="generation_mode"
                  description={<ScreenReaderContent>Generation mode</ScreenReaderContent>}
                  layout="columns"
                  value={generationMode}
                  onChange={(_, value) => setGenerationMode(value)}
                  disabled={generating}
                >
                  <RadioInput value={MODE_STRUCTURED} label="Standard question type" />
                  <RadioInput value={MODE_OPEN} label="Freestyle activity" />
                </RadioInputGroup>
              </View>

              {generationMode === MODE_STRUCTURED && (
                <View as="div" margin="0 0 small medium">
                  <SimpleSelect
                    renderLabel="Question type"
                    value={questionType}
                    onChange={(_, data) => setQuestionType(String(data.value || DEFAULT_STRUCTURED_TYPE))}
                    interaction={generating ? 'disabled' : 'enabled'}
                  >
                    <SimpleSelect.Option id="qtype-mc-single" value="multiple_choice_single_answer">Multiple choice (single answer)</SimpleSelect.Option>
                    <SimpleSelect.Option id="qtype-mc-multi" value="multiple_choice_multiple_answer">Multiple choice (multiple answer)</SimpleSelect.Option>
                    <SimpleSelect.Option id="qtype-matching" value="matching">Matching</SimpleSelect.Option>
                    <SimpleSelect.Option id="qtype-fill-blank" value="fill_in_blank">Fill in the blank</SimpleSelect.Option>
                    <SimpleSelect.Option id="qtype-ordering" value="ordering">Ordering</SimpleSelect.Option>
                    <SimpleSelect.Option id="qtype-numeric" value="numeric">Numeric</SimpleSelect.Option>
                    <SimpleSelect.Option id="qtype-image-hotspot-single" value="image_hotspot_single">Image hotspot (single answer)</SimpleSelect.Option>
                  </SimpleSelect>
                </View>
              )}

              {canManageAssets && appPackage?.id && (
                <View as="div" margin="0 0 medium 0" padding="small" borderWidth="small" borderRadius="medium">
                  <Flex direction="column" gap="small">
                    <Flex justifyItems="space-between" alignItems="center">
                      <Text size="small" weight="bold">Images</Text>
                      <Flex gap="x-small">
                        <Button size="small" onClick={() => setIsImagePanelOpen((open) => !open)}>
                          + Image
                        </Button>
                        {isImagePanelOpen && (
                          <IconButton
                            size="small"
                            screenReaderLabel="Close image panel"
                            onClick={() => setIsImagePanelOpen(false)}
                            disabled={uploadingAsset}
                          >
                            <IconXLine />
                          </IconButton>
                        )}
                        <IconButton
                          size="small"
                          screenReaderLabel="Refresh image list"
                          onClick={() => loadAssets()}
                          disabled={assetsLoading || uploadingAsset}
                        >
                          <IconRefreshLine />
                        </IconButton>
                      </Flex>
                    </Flex>

                    {isImagePanelOpen && (
                      <View as="div" padding="x-small" borderWidth="small" borderRadius="small">
                        <Flex direction="column" gap="small">
                          <Text size="x-small" color="secondary">
                            Copyright declaration is required before upload and cannot be edited later. To change rights metadata, delete and re-upload.
                          </Text>

                          <label style={{ display: 'flex', flexDirection: 'column', gap: '0.35rem' }}>
                            <Text size="small">Image file</Text>
                            <input
                              type="file"
                              accept="image/png,image/jpeg,image/webp"
                              onChange={(e) => setAssetFile(e.target.files?.[0] || null)}
                              disabled={uploadingAsset}
                            />
                          </label>

                          <SimpleSelect
                            renderLabel="Copyright basis (required)"
                            value={assetRightsBasis}
                            onChange={(_, data) => {
                              const next = String(data.value || '')
                              setAssetRightsBasis(next)
                              if (next !== 'creative_commons') setAssetCcLicense('')
                            }}
                            interaction={uploadingAsset ? 'disabled' : 'enabled'}
                          >
                            <SimpleSelect.Option id="rights-basis-empty" value="">Select basis</SimpleSelect.Option>
                            {RIGHTS_BASIS_OPTIONS.map((option) => (
                              <SimpleSelect.Option key={option.value} id={`rights-basis-${option.value}`} value={option.value}>
                                {option.label}
                              </SimpleSelect.Option>
                            ))}
                          </SimpleSelect>

                          {assetRightsBasis === 'creative_commons' && (
                            <SimpleSelect
                              renderLabel="Creative Commons type (required)"
                              value={assetCcLicense}
                              onChange={(_, data) => setAssetCcLicense(String(data.value || ''))}
                              interaction={uploadingAsset ? 'disabled' : 'enabled'}
                            >
                              <SimpleSelect.Option id="rights-cc-empty" value="">Select CC type</SimpleSelect.Option>
                              {CC_LICENSE_OPTIONS.map((option) => (
                                <SimpleSelect.Option key={option.value} id={`rights-cc-${option.value}`} value={option.value}>
                                  {option.label}
                                </SimpleSelect.Option>
                              ))}
                            </SimpleSelect>
                          )}

                          <TextInput
                            renderLabel="Copyright holder (optional)"
                            value={assetCopyrightHolder}
                            onChange={(_, value) => setAssetCopyrightHolder(value)}
                            interaction={uploadingAsset ? 'disabled' : 'enabled'}
                          />

                          <TextInput
                            renderLabel="Label (optional)"
                            value={assetLabel}
                            onChange={(_, value) => setAssetLabel(value)}
                            interaction={uploadingAsset ? 'disabled' : 'enabled'}
                          />

                          <TextInput
                            renderLabel="Alt text (optional)"
                            value={assetAlt}
                            onChange={(_, value) => setAssetAlt(value)}
                            interaction={uploadingAsset ? 'disabled' : 'enabled'}
                          />

                          <TextArea
                            label="Rights note (optional)"
                            value={assetRightsNote}
                            onChange={(e) => setAssetRightsNote(e.target.value)}
                            height="80px"
                            disabled={uploadingAsset}
                          />

                          <Flex gap="small" alignItems="center">
                            <Button
                              onClick={uploadAssetImage}
                              color="primary"
                              disabled={!assetFile || !assetRightsBasis || uploadingAsset || (assetRightsBasis === 'creative_commons' && !assetCcLicense)}
                            >
                              {uploadingAsset ? 'Uploading…' : 'Upload image'}
                            </Button>
                          </Flex>
                        </Flex>
                      </View>
                    )}

                    <View as="div">
                      {assetsLoading ? (
                        <Text size="small" color="secondary">Loading…</Text>
                      ) : assets.length === 0 ? (
                        <Text size="small" color="secondary">No images uploaded for this app.</Text>
                      ) : (
                        <Flex direction="column" gap="x-small" margin="x-small 0 0 0">
                          {assets.map((asset) => (
                            <View key={asset.id} as="div" borderWidth="small" borderRadius="small" padding="x-small">
                              <Flex direction="column" gap="xx-small">
                                <Flex justifyItems="space-between" alignItems="start">
                                  <Text size="small"><strong>{asset.label || asset.id}</strong></Text>
                                  <IconButton
                                    size="small"
                                    screenReaderLabel={`Delete image ${asset.label || asset.id}`}
                                    onClick={() => deleteAssetImage(asset.id, isEditMode)}
                                    disabled={deletingAssetId === asset.id}
                                  >
                                    <IconXLine />
                                  </IconButton>
                                </Flex>
                                <Text size="x-small" color="secondary">
                                  {asset.width}x{asset.height} · {(asset.bytes / 1024).toFixed(1)} KB · {asset.rights_basis}{asset.cc_license ? ` (${asset.cc_license})` : ''}
                                </Text>
                              </Flex>
                            </View>
                          ))}
                        </Flex>
                      )}
                    </View>
                  </Flex>
                </View>
              )}

              <TextArea
                label={<ScreenReaderContent>App description</ScreenReaderContent>}
                placeholder={
                  hasGeneratedApp
                    ? 'What changes would you like to make?…'
                    : 'Describe the learning activity you want…'
                }
                value={prompt}
                onChange={(e) => setPrompt(e.target.value)}
                disabled={generating}
                height="100px"
              />

              <View as="div" margin="0 0 small 0">
                <ToggleDetails
                  summary="Advanced"
                  expanded={showAdvancedControls}
                  onToggle={(_, expanded) => setShowAdvancedControls(expanded)}
                >
                  <View as="div" margin="small 0 0 0">
                    <SimpleSelect
                      renderLabel="Vision mode"
                      value={visionMode}
                      onChange={(_, data) => setVisionMode(String(data.value || VISION_MODE_AUTO))}
                      interaction={generating ? 'disabled' : 'enabled'}
                    >
                      <SimpleSelect.Option id="vision-auto" value={VISION_MODE_AUTO}>Auto (recommended)</SimpleSelect.Option>
                      <SimpleSelect.Option id="vision-force" value={VISION_MODE_FORCE}>On (force vision)</SimpleSelect.Option>
                      <SimpleSelect.Option id="vision-off" value={VISION_MODE_OFF}>Off (text only)</SimpleSelect.Option>
                    </SimpleSelect>
                  </View>

                  {generationMode === MODE_STRUCTURED && (
                    <View as="div" margin="small 0 0 0">
                      <Checkbox
                        label="Show correct answer after 2 incorrect attempts"
                        checked={appPackage?.questions?.[0]?.reveal_correct_after_two_incorrect_attempts !== false}
                        onChange={(e) => updateStructuredRevealSetting(Boolean(e.target.checked))}
                        disabled={generating || !appPackage}
                      />
                    </View>
                  )}
                </ToggleDetails>
              </View>

              <Flex gap="small" margin="medium 0 0 0" justifyItems="end" style={{ flexWrap: 'wrap' }}>
                {hasGeneratedApp && !showStartAgainConfirm && (
                  <Button
                    onClick={() => setShowStartAgainConfirm(true)}
                    disabled={generating}
                  >
                    Start again
                  </Button>
                )}

                {hasGeneratedApp && previousApp && hasUnsavedChanges && !generating && (
                  <Button onClick={undoRevision}>
                    Undo
                  </Button>
                )}

                <Button
                  onClick={generateApp}
                  disabled={generating || !prompt.trim() || !accessToken || (generationMode === MODE_STRUCTURED && !questionType)}
                >
                  {generating
                    ? (hasGeneratedApp ? 'Revising…' : 'Generating…')
                    : (hasGeneratedApp ? 'Revise app' : 'Generate app')
                  }
                </Button>
              </Flex>

              {isEditMode && (
                <Flex gap="small" margin="small 0" justifyItems="end">
                  <Button
                    onClick={cancelEditSession}
                    disabled={generating}
                  >
                    Cancel
                  </Button>
                  <Button
                    onClick={saveCurrentRevision}
                    color="primary"
                    disabled={generating || !hasUnsavedChanges}
                  >
                    Save
                  </Button>
                </Flex>
              )}

              {isDeepLinkMode && (
                <Flex gap="small" margin="small 0" justifyItems="end">
                  <Button
                    onClick={cancelDeepLinkLaunch}
                    disabled={insertingDeepLink || savingForInsert || generating}
                  >
                    Cancel
                  </Button>
                  <Button
                    onClick={insertDeepLinkItem}
                    disabled={insertingDeepLink || savingForInsert || generating || !appPackage?.id || !hasGeneratedApp}
                    color="primary"
                  >
                    {savingForInsert ? 'Saving…' : insertingDeepLink ? 'Inserting…' : 'Insert into Canvas'}
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
                If you save changes, saved learner progress for this activity will be reset.
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
                onClick={cancelEditSession}
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
