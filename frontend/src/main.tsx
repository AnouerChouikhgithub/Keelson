import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router'
import './index.css'
import { AppRouter } from './app/AppRouter'
import { ToastProvider } from './components/Toast'
import { I18nProvider } from './i18n/I18nProvider'
import { AuthProvider } from './session/AuthProvider'
import { ThemeProvider } from './theme/ThemeProvider'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <I18nProvider>
        <ThemeProvider>
          <ToastProvider>
            <AuthProvider>
              <AppRouter />
            </AuthProvider>
          </ToastProvider>
        </ThemeProvider>
      </I18nProvider>
    </BrowserRouter>
  </StrictMode>,
)
