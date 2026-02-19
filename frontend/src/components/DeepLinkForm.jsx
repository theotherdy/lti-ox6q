import { useEffect, useRef } from 'react'

export default function DeepLinkForm({ deepLinkReturnUrl, deepLinkingJwt = '' }) {
  const formRef = useRef(null)

  useEffect(() => {
    if (!deepLinkReturnUrl) return
    if (!deepLinkingJwt) return
    formRef.current?.submit()
  }, [deepLinkReturnUrl, deepLinkingJwt])

  if (!deepLinkReturnUrl) return null

  return (
    <form ref={formRef} method="post" action={deepLinkReturnUrl}>
      <input type="hidden" name="JWT" value={deepLinkingJwt} />
    </form>
  )
}
