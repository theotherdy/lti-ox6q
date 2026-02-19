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

function buildSrcDoc(pkg, channel) {
  const title = pkg.title || 'Learning App'
  const html = pkg.html || "<div id='app'></div>"
  const css = pkg.css || ''
  const js = pkg.js || ''

  // A tiny RPC helper between iframe ↔ parent.
  return `<!doctype html>
<html>
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>${title}</title>
    <style>html,body{margin:0;padding:0;}</style>
    <style>${css}</style>
  </head>
  <body>
    ${html}
    <script>
      (function(){
        const CHANNEL = ${JSON.stringify(channel)};
        const pending = new Map();
        let nextId = 1;

        function callParent(type, payload){
          return new Promise((resolve, reject) => {
            const id = nextId++;
            pending.set(id, {resolve, reject});
            window.parent.postMessage({ __ox6q: true, channel: CHANNEL, id, type, payload }, '*');
          });
        }

        window.addEventListener('message', (event) => {
          const msg = event.data;
          if (!msg || msg.__ox6q !== true || msg.channel !== CHANNEL || !msg.replyTo) return;
          const entry = pending.get(msg.replyTo);
          if (!entry) return;
          pending.delete(msg.replyTo);
          if (msg.ok) entry.resolve(msg.result);
          else entry.reject(new Error(msg.error || 'RPC failed'));
        });

        window.sdk = {
          getState: () => callParent('getState'),
          setState: (state) => callParent('setState', {state}),
          notify: (input) => {
            const payload =
            typeof input === 'string'
              ? { message: input, variant: 'info' }
              : { message: String(input?.message ?? ''), variant: String(input?.variant ?? 'info') }
              return callParent('notify', payload)
          }
        };
        
        // Nice dev-friendly shim: if generated apps call alert(), turn it into a notify.
        window.alert = (msg) => window.sdk.notify({ variant: 'info', message: String(msg) });

        let resizeTimer = null;
        let lastHeight = 0;

        function getDocHeight() {
          const body = document.body;
          const html = document.documentElement;
          if (!body || !html) return 0;
          return Math.max(
            body.scrollHeight,
            body.offsetHeight,
            html.scrollHeight,
            html.offsetHeight,
            html.clientHeight
          );
        }

        function postResize() {
          const next = Math.ceil(getDocHeight());
          if (!Number.isFinite(next) || next <= 0) return;
          if (Math.abs(next - lastHeight) < 2) return;
          lastHeight = next;
          window.parent.postMessage({
            __ox6q: true,
            channel: CHANNEL,
            type: 'resize',
            payload: { height: next }
          }, '*');
        }

        function scheduleResize() {
          if (resizeTimer) clearTimeout(resizeTimer);
          resizeTimer = setTimeout(postResize, 60);
        }

        if (window.ResizeObserver) {
          const ro = new ResizeObserver(scheduleResize);
          ro.observe(document.documentElement);
          if (document.body) ro.observe(document.body);
        }

        window.addEventListener('resize', scheduleResize);
        window.addEventListener('load', () => {
          postResize();
          setTimeout(postResize, 100);
          setTimeout(postResize, 400);
        });

        const mo = new MutationObserver(scheduleResize);
        mo.observe(document.documentElement, {
          childList: true,
          subtree: true,
          characterData: true,
          attributes: true
        });

        postResize();
      })();
    </script>
    <script>
${js}
    </script>
  </body>
</html>`;
}

export default function Runner({ apiBase, token, pkg, onError }) {
  const iframeRef = useRef(null)
  const [notices, setNotices] = useState([]) //used to display notifications from iFramed app via callParent('notify', payload);
  const [iframeHeight, setIframeHeight] = useState(DEFAULT_IFRAME_HEIGHT)
  const channel = useMemo(() => createChannelToken(), [pkg?.id])

  const srcDoc = useMemo(() => {
    if (!pkg) return null  //pkg will be null when first loads
    return buildSrcDoc(pkg, channel)
  }, [pkg, channel])

  //helper for pushing notices from iFramed app into Alert in this container app
  function pushNotice({ message, variant }) {
    const id = `${Date.now()}-${Math.random().toString(16).slice(2)}`
    const msg = String(message || '').slice(0, 300)

    // Instructure variants commonly include: info, success, warning, danger
    const v = (variant === 'error') ? 'danger' : variant
    const safeVariant = ['info', 'success', 'warning', 'danger'].includes(v) ? v : 'info'

    setNotices((xs) => [...xs, { id, message: msg, variant: safeVariant }].slice(-3))
    setTimeout(() => setNotices((xs) => xs.filter((n) => n.id !== id)), 3000)
  }

  useEffect(() => {
    setIframeHeight(DEFAULT_IFRAME_HEIGHT)
  }, [pkg?.id])

  useEffect(() => {
    const iframe = iframeRef.current
    if (!iframe) return

    async function handleRpc(event) {
      // Only accept messages from our iframe
      if (event.source !== iframe.contentWindow) return
      const msg = event.data
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
        iframe.contentWindow.postMessage(
          ok
            ? { __ox6q: true, channel, replyTo: msg.id, ok: true, result: resultOrError }
            : { __ox6q: true, channel, replyTo: msg.id, ok: false, error: String(resultOrError) },
          '*'
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
          pushNotice({
            message,
            variant,
          })
          await reply(true, { ok: true })
          return
        }

        throw new Error(`Unknown RPC type: ${msg.type}`)
      } catch (e) {
        await reply(false, e)
      }
    }

    window.addEventListener('message', handleRpc)
    return () => window.removeEventListener('message', handleRpc)
  }, [apiBase, channel, onError, pkg, token])

  if (!token) {
    return (
      <View as="div" padding="medium">
        <Text color="secondary">Not authenticated yet.</Text>
        <Text color="secondary">
          Launch this tool from your LMS (LTI) to initialise a session.
        </Text>
      </View>
    )
  }

  if (!pkg) {
    return (
      <View as="div" padding="medium">
        <Text color="secondary">No application loaded yet. </Text>
        <Text color="secondary">
          Generate an app to begin.
        </Text>
      </View>
    )
  }

  return (
    <View as="div">
      {/* App iframe */}
      <View
        as="div"
        borderWidth="small"
        borderRadius="medium"
        background="primary"
        margin="0"
        position="relative"
      >
        {/* !!!!!Don't allow any powers other than allow-scripts without serious thought!!!!!! */}
        <iframe
          ref={iframeRef}
          title="Learning app"
          sandbox="allow-scripts"
          srcDoc={srcDoc}
          style={{ width: '100%', height: `${iframeHeight}px`, border: 'none', borderRadius: '8px', display: 'block' }}
        />
        {/* Notification overlay */}
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
