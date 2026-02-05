import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'
import { canvas } from '@instructure/ui-themes'
import { InstUISettingsProvider } from '@instructure/emotion'

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <InstUISettingsProvider theme={canvas}>
      <App />
    </InstUISettingsProvider>
  </React.StrictMode>
)
