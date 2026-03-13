import React, { useEffect, useMemo, useRef, useState } from 'react'
import { Alert } from '@instructure/ui-alerts'
import { View } from '@instructure/ui-view'
import { Text } from '@instructure/ui-text'
import { Flex } from '@instructure/ui-flex'

const DEFAULT_IFRAME_HEIGHT = 600
const MIN_IFRAME_HEIGHT = 320
const MAX_IFRAME_HEIGHT = 4000
const MAX_SET_STATE_PAYLOAD_BYTES = 20000
const MAX_NOTIFY_PAYLOAD_BYTES = 2000
const ALLOWED_MESSAGE_TYPES = new Set(['resize', 'getState', 'setState', 'notify'])

function createChannelToken() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `${Date.now()}-${Math.random().toString(16).slice(2)}`
}

function fitsJsonSize(value, maxBytes) {
  try {
    return JSON.stringify(value).length <= maxBytes
  } catch {
    return false
  }
}

function jsonHeaders(token) {
  const h = { 'Content-Type': 'application/json' }
  if (token) h.Authorization = `Bearer ${token}`
  return h
}

export default function Runner({ apiBase, token, pkg, onError }) {
  const iframeRef = useRef(null)
  const [notices, setNotices] = useState([])
  const [iframeHeight, setIframeHeight] = useState(DEFAULT_IFRAME_HEIGHT)
  const channel = useMemo(() => createChannelToken(), [pkg?.id, pkg?.js, pkg?.css, pkg?.html, pkg?.title])

  function pushNotice({ message, variant }) {
    const id = `${Date.now()}-${Math.random().toString(16).slice(2)}`
    const msg = String(message || '').slice(0, 300)
    const v = (variant === 'danger') ? 'error' : variant
    const safeVariant = ['info', 'success', 'warning', 'error'].includes(v) ? v : 'info'

    setNotices((xs) => [...xs, { id, message: msg, variant: safeVariant }].slice(-3))
    setTimeout(() => setNotices((xs) => xs.filter((n) => n.id !== id)), 3000)
  }

  useEffect(() => {
    setIframeHeight(DEFAULT_IFRAME_HEIGHT)
  }, [pkg?.id, pkg?.js])

  useEffect(() => {
    const iframe = iframeRef.current
    if (!iframe || !pkg) return

    function postInit() {
      iframe.contentWindow?.postMessage({
        __ox6q: true,
        type: 'open-react:init',
        channel,
        payload: { pkg },
      }, '*')
    }

    async function handleRpc(event) {
      if (event.source !== iframe.contentWindow) return
      const msg = event.data

      if (msg?.__ox6q === true && msg?.type === 'open-react:ready') {
        postInit()
        return
      }

      if (!msg || msg.__ox6q !== true || msg.channel !== channel || !msg.type) return
      if (!ALLOWED_MESSAGE_TYPES.has(msg.type)) return

      if (msg.type === 'resize') {
        const rawHeight = msg.payload?.height
        const parsed = Number(rawHeight)
        if (!Number.isFinite(parsed)) return
        const clamped = Math.max(MIN_IFRAME_HEIGHT, Math.min(MAX_IFRAME_HEIGHT, Math.ceil(parsed)))
        setIframeHeight((prev) => (prev === clamped ? prev : clamped))
        return
      }

      if (!msg.id) return

      const reply = async (ok, resultOrError) => {
        iframe.contentWindow?.postMessage(
          ok
            ? { __ox6q: true, channel, replyTo: msg.id, ok: true, result: resultOrError }
            : { __ox6q: true, channel, replyTo: msg.id, ok: false, error: String(resultOrError) },
          '*',
        )
      }

      try {
        if (msg.type === 'getState') {
          const res = await fetch(`${apiBase}/api/apps/${pkg.id}/state`, {
            headers: jsonHeaders(token),
          })
          if (res.status === 401) {
            onError?.('Session expired — please re-launch.')
            throw new Error('Session expired')
          }
          const body = await res.json().catch(() => ({}))
          if (!res.ok) throw new Error(body.error || res.statusText)
          await reply(true, body.state)
          return
        }

        if (msg.type === 'setState') {
          if (!msg.payload || typeof msg.payload !== 'object' || !Object.prototype.hasOwnProperty.call(msg.payload, 'state')) {
            throw new Error('setState payload must include state')
          }
          if (!fitsJsonSize(msg.payload?.state, MAX_SET_STATE_PAYLOAD_BYTES)) {
            throw new Error('setState payload too large')
          }
          const res = await fetch(`${apiBase}/api/apps/${pkg.id}/state`, {
            method: 'PUT',
            headers: jsonHeaders(token),
            body: JSON.stringify({ state: msg.payload?.state }),
          })
          if (res.status === 401) {
            onError?.('Session expired — please re-launch.')
            throw new Error('Session expired')
          }
          const body = await res.json().catch(() => ({}))
          if (!res.ok) throw new Error(body.error || res.statusText)
          await reply(true, body)
          return
        }

        if (msg.type === 'notify') {
          if (!msg.payload || typeof msg.payload !== 'object') {
            throw new Error('notify payload must be an object')
          }
          const message = msg.payload?.message
          const variant = msg.payload?.variant
          if (message !== undefined && typeof message !== 'string') {
            throw new Error('notify.message must be a string')
          }
          if (variant !== undefined && typeof variant !== 'string') {
            throw new Error('notify.variant must be a string')
          }
          if (!fitsJsonSize(msg.payload, MAX_NOTIFY_PAYLOAD_BYTES)) {
            throw new Error('notify payload too large')
          }
          pushNotice({ message, variant })
          await reply(true, { ok: true })
          return
        }

        throw new Error(`Unknown RPC type: ${msg.type}`)
      } catch (e) {
        await reply(false, e)
      }
    }

    window.addEventListener('message', handleRpc)
    postInit()
    const t1 = setTimeout(postInit, 120)
    const t2 = setTimeout(postInit, 350)
    const t3 = setTimeout(postInit, 900)

    return () => {
      clearTimeout(t1)
      clearTimeout(t2)
      clearTimeout(t3)
      window.removeEventListener('message', handleRpc)
    }
  }, [apiBase, channel, onError, pkg, token])

  if (!token) {
    return (
      <View as="div" padding="medium">
        <Text color="secondary">Not authenticated yet.</Text>
        <Text color="secondary">Launch this tool from your LMS (LTI) to initialise a session.</Text>
      </View>
    )
  }

  if (!pkg) {
    return (
      <View as="div" padding="medium">
        <Text color="secondary">No application loaded yet.</Text>
        <Text color="secondary">Generate an app to begin.</Text>
      </View>
    )
  }

  return (
    <View as="div">
      <View as="div" borderWidth="small" borderRadius="medium" background="primary" margin="0" position="relative">
        <iframe
          ref={iframeRef}
          key={channel}
          title="Learning app"
          sandbox="allow-scripts"
          src={`${import.meta.env.BASE_URL}open-react-runner.html`}
          style={{ width: '100%', height: `${iframeHeight}px`, border: 'none', borderRadius: '8px', display: 'block' }}
        />
        <div
          style={{
            position: 'absolute',
            top: '0.75rem',
            left: '50%',
            transform: 'translateX(-50%)',
            width: 'min(520px, calc(100% - 1.5rem))',
            zIndex: 15,
          }}
        >
          <Flex direction="column" gap="x-small">
            {notices.map((n) => (
              <Alert key={n.id} variant={n.variant} margin="0">
                {n.message}
              </Alert>
            ))}
          </Flex>
        </div>
      </View>
    </View>
  )
}
