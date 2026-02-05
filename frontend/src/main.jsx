import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'
import { theme as canvasTheme } from '@instructure/canvas-theme'
import { EmotionThemeProvider } from '@instructure/emotion'

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <EmotionThemeProvider theme={canvasTheme}>
      <App />
    </EmotionThemeProvider>
  </React.StrictMode>
)
