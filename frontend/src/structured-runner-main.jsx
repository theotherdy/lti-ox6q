import React, { useEffect, useMemo, useState } from 'react'
import ReactDOM from 'react-dom/client'
import { canvas } from '@instructure/ui-themes'
import { InstUISettingsProvider } from '@instructure/emotion'
import { View } from '@instructure/ui-view'
import { Text } from '@instructure/ui-text'
import StructuredQuestionRunner from './components/StructuredQuestionRunner'

function StructuredRunnerApp() {
  const [pkg, setPkg] = useState(null)
  const [channel, setChannel] = useState(null)

  useEffect(() => {
    const announceReady = () => {
      window.parent.postMessage({
        __ox6q: true,
        type: 'structured:ready',
      }, '*')
    }

    announceReady()
    const readyTimer = setTimeout(announceReady, 300)

    function handleMessage(event) {
      if (event.source !== window.parent) return
      const msg = event.data
      if (!msg || msg.__ox6q !== true || msg.type !== 'structured:init') return
      if (typeof msg.channel !== 'string' || msg.channel.trim() === '') return
      if (!msg.payload || typeof msg.payload !== 'object') return
      if (!msg.payload.pkg || typeof msg.payload.pkg !== 'object') return

      setChannel(msg.channel)
      setPkg(msg.payload.pkg)
    }

    window.addEventListener('message', handleMessage)
    return () => {
      clearTimeout(readyTimer)
      window.removeEventListener('message', handleMessage)
    }
  }, [])

  const ready = useMemo(() => Boolean(channel && pkg), [channel, pkg])

  useEffect(() => {
    if (!ready) return

    let resizeTimer = null
    let lastHeight = 0

    const getContentHeight = () => {
      const root = document.getElementById('root')
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

    const postResize = () => {
      const nextHeight = getContentHeight()
      if (!Number.isFinite(nextHeight) || nextHeight <= 0) return
      const rounded = Math.ceil(nextHeight)
      if (Math.abs(rounded - lastHeight) < 2) return
      lastHeight = rounded
      window.parent.postMessage({
        __ox6q: true,
        channel,
        type: 'resize',
        payload: { height: rounded },
      }, '*')
    }

    const scheduleResize = () => {
      if (resizeTimer) clearTimeout(resizeTimer)
      resizeTimer = setTimeout(postResize, 60)
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

    postResize()
    const t1 = setTimeout(postResize, 120)
    const t2 = setTimeout(postResize, 360)

    return () => {
      if (resizeTimer) clearTimeout(resizeTimer)
      clearTimeout(t1)
      clearTimeout(t2)
      window.removeEventListener('resize', scheduleResize)
      mutationObserver.disconnect()
      resizeObserver?.disconnect()
    }
  }, [channel, ready, pkg?.id, pkg?.questions?.[0]?.id])

  if (!pkg) {
    return (
      <View as="div" padding="small">
        <Text color="secondary">Waiting for structured question payload…</Text>
      </View>
    )
  }

  return <StructuredQuestionRunner pkg={pkg} />
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <InstUISettingsProvider theme={canvas}>
      <StructuredRunnerApp />
    </InstUISettingsProvider>
  </React.StrictMode>,
)
