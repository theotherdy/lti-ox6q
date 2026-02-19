import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { View } from '@instructure/ui-view'
import { Text } from '@instructure/ui-text'

const DEFAULT_IFRAME_HEIGHT = 220
const MIN_IFRAME_HEIGHT = 120
const MAX_IFRAME_HEIGHT = 4000

function createChannelToken() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `${Date.now()}-${Math.random().toString(16).slice(2)}`
}

export default function StructuredRunnerFrame({ pkg }) {
  const iframeRef = useRef(null)
  const [iframeHeight, setIframeHeight] = useState(DEFAULT_IFRAME_HEIGHT)
  const channel = useMemo(() => createChannelToken(), [pkg?.id])

  const postInit = useCallback(() => {
    const target = iframeRef.current?.contentWindow
    if (!target || !pkg) return

    target.postMessage({
      __ox6q: true,
      channel,
      type: 'structured:init',
      payload: { pkg },
    }, '*')
  }, [channel, pkg])

  useEffect(() => {
    setIframeHeight(DEFAULT_IFRAME_HEIGHT)
  }, [pkg?.id])

  useEffect(() => {
    const iframe = iframeRef.current
    if (!iframe) return

    function handleMessage(event) {
      if (event.source !== iframe.contentWindow) return
      const msg = event.data
      if (msg?.__ox6q === true && msg?.type === 'structured:ready') {
        postInit()
        return
      }
      if (!msg || msg.__ox6q !== true || msg.channel !== channel || msg.type !== 'resize') return
      const rawHeight = msg.payload?.height
      const parsed = Number(rawHeight)
      if (!Number.isFinite(parsed)) return
      const clamped = Math.max(MIN_IFRAME_HEIGHT, Math.min(MAX_IFRAME_HEIGHT, Math.ceil(parsed)))
      setIframeHeight((prev) => (prev === clamped ? prev : clamped))
    }

    window.addEventListener('message', handleMessage)
    return () => window.removeEventListener('message', handleMessage)
  }, [channel, postInit])

  useEffect(() => {
    postInit()
    const t1 = setTimeout(postInit, 100)
    const t2 = setTimeout(postInit, 350)
    const t3 = setTimeout(postInit, 1000)
    return () => {
      clearTimeout(t1)
      clearTimeout(t2)
      clearTimeout(t3)
    }
  }, [postInit])

  if (!pkg) {
    return (
      <View as="div" padding="small">
        <Text color="secondary">No structured question loaded.</Text>
      </View>
    )
  }

  return (
    <View as="div" background="primary" margin="0">
      <iframe
        ref={iframeRef}
        title="Structured question"
        sandbox="allow-scripts"
        src="/structured-runner.html"
        onLoad={postInit}
        style={{ width: '100%', height: `${iframeHeight}px`, border: 'none', borderRadius: 0, display: 'block' }}
      />
    </View>
  )
}
