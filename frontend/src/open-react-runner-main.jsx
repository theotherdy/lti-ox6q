import React from 'react'
import ReactDOM from 'react-dom/client'

const DEFAULT_HTML = '<div id="app"></div>'

function ensureStyleTag() {
  let styleTag = document.getElementById('generated-style')
  if (!styleTag) {
    styleTag = document.createElement('style')
    styleTag.id = 'generated-style'
    document.head.appendChild(styleTag)
  }
  return styleTag
}

function createSdkBridge(channel) {
  const pending = new Map()
  let nextId = 1

  function callParent(type, payload) {
    return new Promise((resolve, reject) => {
      const id = nextId++
      pending.set(id, { resolve, reject })
      window.parent.postMessage({ __ox6q: true, channel, id, type, payload }, '*')
    })
  }

  function handleReply(event) {
    const msg = event.data
    if (!msg || msg.__ox6q !== true || msg.channel !== channel || !msg.replyTo) return
    const entry = pending.get(msg.replyTo)
    if (!entry) return
    pending.delete(msg.replyTo)
    if (msg.ok) entry.resolve(msg.result)
    else entry.reject(new Error(msg.error || 'RPC failed'))
  }

  window.addEventListener('message', handleReply)

  return {
    sdk: {
      getState: () => callParent('getState'),
      setState: (state) => callParent('setState', { state }),
      notify: (input) => {
        const payload = typeof input === 'string'
          ? { message: input, variant: 'info' }
          : { message: String(input?.message ?? ''), variant: String(input?.variant ?? 'info') }
        return callParent('notify', payload)
      },
    },
    destroy: () => window.removeEventListener('message', handleReply),
  }
}

function setupResize(channel) {
  let resizeTimer = null
  let lastHeight = 0

  function getDocHeight() {
    const body = document.body
    const html = document.documentElement
    if (!body || !html) return 0
    return Math.max(body.scrollHeight, body.offsetHeight, html.scrollHeight, html.offsetHeight, html.clientHeight)
  }

  function postResize() {
    const next = Math.ceil(getDocHeight())
    if (!Number.isFinite(next) || next <= 0) return
    if (Math.abs(next - lastHeight) < 2) return
    lastHeight = next
    window.parent.postMessage({ __ox6q: true, channel, type: 'resize', payload: { height: next } }, '*')
  }

  function scheduleResize() {
    if (resizeTimer) clearTimeout(resizeTimer)
    resizeTimer = setTimeout(postResize, 60)
  }

  const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(scheduleResize) : null
  ro?.observe(document.documentElement)
  if (document.body) ro?.observe(document.body)

  const mo = new MutationObserver(scheduleResize)
  mo.observe(document.documentElement, { childList: true, subtree: true, characterData: true, attributes: true })

  window.addEventListener('resize', scheduleResize)
  postResize()
  const t1 = setTimeout(postResize, 100)
  const t2 = setTimeout(postResize, 350)

  return () => {
    if (resizeTimer) clearTimeout(resizeTimer)
    clearTimeout(t1)
    clearTimeout(t2)
    window.removeEventListener('resize', scheduleResize)
    ro?.disconnect()
    mo.disconnect()
  }
}

function errorMessageFrom(value) {
  if (value instanceof Error) return value.message || String(value)
  if (typeof value === 'string') return value
  return String(value ?? 'Unknown runtime error')
}

function reportRuntimeError(sdk, value, context = 'runtime') {
  const message = errorMessageFrom(value)
  console.error(`[open-react-runner] ${context} error:`, value)
  sdk.notify({ variant: 'error', message: `Runtime error: ${message}` }).catch(() => {})
}

class RunnerErrorBoundary extends React.Component {
  constructor(props) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error) {
    return { error }
  }

  componentDidCatch(error) {
    this.props.onError?.(error)
  }

  render() {
    if (this.state.error) {
      return (
        <div
          style={{
            margin: '12px',
            border: '1px solid #c23b22',
            borderRadius: '8px',
            background: '#fff5f5',
            padding: '12px',
            color: '#5f2120',
            fontFamily: 'sans-serif',
          }}
        >
          <strong>Activity runtime error</strong>
          <div style={{ marginTop: '6px' }}>{errorMessageFrom(this.state.error)}</div>
        </div>
      )
    }
    return this.props.children
  }
}

function executePackage(pkg, sdk, channel) {
  const root = document.getElementById('root')
  if (!root) return

  document.title = String(pkg?.title || 'Learning App')
  root.innerHTML = String(pkg?.html || DEFAULT_HTML)
  ensureStyleTag().textContent = String(pkg?.css || '')

  const onWindowError = (event) => {
    reportRuntimeError(sdk, event?.error || event?.message || event, 'window')
  }
  const onUnhandledRejection = (event) => {
    reportRuntimeError(sdk, event?.reason || event, 'promise')
  }
  window.addEventListener('error', onWindowError)
  window.addEventListener('unhandledrejection', onUnhandledRejection)

  // Expose globals expected by transpiled package wrapper.
  window.React = React
  const baseCreateRoot = typeof ReactDOM.createRoot === 'function' ? ReactDOM.createRoot.bind(ReactDOM) : null
  const safeCreateRoot = baseCreateRoot
    ? (container, options) => {
      const realRoot = baseCreateRoot(container, options)
      return {
        ...realRoot,
        render: (element) => realRoot.render(
          <RunnerErrorBoundary onError={(e) => reportRuntimeError(sdk, e, 'react')}>
            {element}
          </RunnerErrorBoundary>,
        ),
      }
    }
    : null

  window.ReactDOM = {
    ...ReactDOM,
    ...(safeCreateRoot ? { createRoot: safeCreateRoot } : {}),
  }
  if (typeof window.ReactDOM.render !== 'function' && typeof window.ReactDOM.createRoot === 'function') {
    // Compatibility for model outputs still using legacy ReactDOM.render API.
    window.ReactDOM.render = (element, container) => {
      const root = window.ReactDOM.createRoot(container)
      root.render(element)
      return root
    }
  }
  window.sdk = sdk

  try {
    const code = String(pkg?.js || '')
    // eslint-disable-next-line no-new-func
    const run = new Function(code)
    run()
  } catch (e) {
    reportRuntimeError(sdk, e, 'execute')
  }

  const cleanupResize = setupResize(channel)
  return () => {
    cleanupResize?.()
    window.removeEventListener('error', onWindowError)
    window.removeEventListener('unhandledrejection', onUnhandledRejection)
  }
}

function RunnerApp() {
  const active = React.useRef({ cleanup: null, bridgeCleanup: null })

  React.useEffect(() => {
    function announceReady() {
      window.parent.postMessage({ __ox6q: true, type: 'open-react:ready' }, '*')
    }

    announceReady()
    const readyTimer = setTimeout(announceReady, 300)

    function handleMessage(event) {
      if (event.source !== window.parent) return
      const msg = event.data
      if (!msg || msg.__ox6q !== true || msg.type !== 'open-react:init') return
      if (typeof msg.channel !== 'string' || msg.channel.trim() === '') return
      if (!msg.payload || typeof msg.payload !== 'object' || !msg.payload.pkg) return

      active.current.cleanup?.()
      active.current.bridgeCleanup?.()

      const channel = msg.channel
      const bridge = createSdkBridge(channel)
      active.current.bridgeCleanup = bridge.destroy
      active.current.cleanup = executePackage(msg.payload.pkg, bridge.sdk, channel)
    }

    window.addEventListener('message', handleMessage)
    return () => {
      clearTimeout(readyTimer)
      window.removeEventListener('message', handleMessage)
      active.current.cleanup?.()
      active.current.bridgeCleanup?.()
    }
  }, [])

  return null
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <RunnerApp />
  </React.StrictMode>,
)
