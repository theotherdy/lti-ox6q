import React, { useEffect, useMemo, useRef, useState } from 'react'
import { Alert } from '@instructure/ui-alerts'
import { View } from '@instructure/ui-view'
import { ToggleDetails } from '@instructure/ui-toggle-details'
import { Heading } from '@instructure/ui-heading'
import { List } from '@instructure/ui-list'
import { Badge } from '@instructure/ui-badge'
import { Text } from '@instructure/ui-text'
import { Flex } from '@instructure/ui-flex'



function jsonHeaders(token) {
  const h = { 'Content-Type': 'application/json' }
  if (token) h.Authorization = `Bearer ${token}`
  return h
}

function buildSrcDoc(pkg) {
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
    <style>${css}</style>
  </head>
  <body>
    ${html}
    <script>
      (function(){
        const pending = new Map();
        let nextId = 1;

        function callParent(type, payload){
          return new Promise((resolve, reject) => {
            const id = nextId++;
            pending.set(id, {resolve, reject});
            window.parent.postMessage({ __ox6q: true, id, type, payload }, '*');
          });
        }

        window.addEventListener('message', (event) => {
          const msg = event.data;
          if (!msg || msg.__ox6q !== true || !msg.replyTo) return;
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
  const [log, setLog] = useState([])
  const [notices, setNotices] = useState([]) //used to display notifications from iFramed app via callParent('notify', payload);

  const srcDoc = useMemo(() => {
    if (!pkg) return null  //pkg will be null when first loads
    return buildSrcDoc(pkg)
  }, [pkg])

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
    const iframe = iframeRef.current
    if (!iframe) return

    async function handleRpc(event) {
      // Only accept messages from our iframe
      if (event.source !== iframe.contentWindow) return
      const msg = event.data
      if (!msg || msg.__ox6q !== true || !msg.id || !msg.type) return

      const reply = async (ok, resultOrError) => {
        iframe.contentWindow.postMessage(
          ok
            ? { __ox6q: true, replyTo: msg.id, ok: true, result: resultOrError }
            : { __ox6q: true, replyTo: msg.id, ok: false, error: String(resultOrError) },
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
          setLog((l) => [`getState → ${JSON.stringify(body.state)}`, ...l].slice(0, 8))
          await reply(true, body.state)
          return
        }

        if (msg.type === 'setState') {
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
          setLog((l) => [`setState ← ${JSON.stringify(msg.payload?.state)}`, ...l].slice(0, 8))
          await reply(true, body)
          return
        }

        if (msg.type === 'notify') {
          pushNotice({
            message: msg.payload?.message,
            variant: msg.payload?.variant,
          })
          setLog((l) => [`notify ← ${JSON.stringify(msg.payload)}`, ...l].slice(0, 8))
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
  }, [apiBase, onError, pkg, token])

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
        <Text color="secondary">No application loaded yet.</Text>
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
        margin="0 0 medium 0"
        position="relative"
      >
        {/* !!!!!Don't allow any powers other than allow-scripts without serious thought!!!!!! */}
        <iframe
          ref={iframeRef}
          title="Learning app"
          sandbox="allow-scripts"
          srcDoc={srcDoc}
          style={{ width: '100%', height: '600px', border: 'none', borderRadius: '8px' }}
        />
        {/* Notification overlay */}
        <View
          as="div"
          position="absolute"
          insetInlineEnd="small"
          insetBlockStart="small"
          width="360px"
        >
          <Flex direction="column" gap="x-small">
            {notices.map((n) => (
              <Alert key={n.id} variant={n.variant} margin="0">
                {n.message}
              </Alert>
            ))}
          </Flex>
        </View>
      </View>

      {/* Collapsible SDK log */}
      <ToggleDetails
        summary={
          <Flex gap="small" alignItems="center">
            <Heading level="h4">SDK Call Log</Heading>
            <Badge count={log.length} />
          </Flex>
        }
        defaultExpanded={false}
      >
        <View as="div" background="secondary" padding="small" borderRadius="medium">
          {log.length === 0 ? (
            <Text color="secondary">No SDK calls yet</Text>
          ) : (
            <List isUnstyled margin="0">
              {log.map((x, i) => (
                <List.Item key={i}>
                  <Text fontFamily="monospace" size="small">
                    {x}
                  </Text>
                </List.Item>
              ))}
            </List>
          )}
        </View>
      </ToggleDetails>
    </View>
  )
}
