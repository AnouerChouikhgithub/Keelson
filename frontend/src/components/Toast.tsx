import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { Icon } from './Icon'
import { ToastContext } from './ToastContext'
import type { ToastApi, ToastTone } from './ToastContext'
import { useI18n } from '../i18n/I18nContext'
import './Toast.css'

type ToastEntry = { id: number; message: string; tone: ToastTone }

const AUTO_DISMISS_MS = 5_000
const MAX_VISIBLE = 4

/**
 * Announcements live in a polite live region, never take focus, and dismiss
 * themselves after five seconds. Each toast also has its own button, so a
 * keyboard user is not forced to wait for the timer.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const { m } = useI18n()
  const [toasts, setToasts] = useState<ToastEntry[]>([])
  const nextId = useRef(0)
  const timers = useRef(new Set<ReturnType<typeof setTimeout>>())

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  const show = useCallback((message: string, tone: ToastTone = 'info') => {
    nextId.current += 1
    const id = nextId.current
    setToasts((current) => [...current.slice(-(MAX_VISIBLE - 1)), { id, message, tone }])
    const timer = setTimeout(() => {
      timers.current.delete(timer)
      dismiss(id)
    }, AUTO_DISMISS_MS)
    timers.current.add(timer)
  }, [dismiss])

  useEffect(() => {
    const pending = timers.current
    return () => {
      for (const timer of pending) clearTimeout(timer)
      pending.clear()
    }
  }, [])

  const api = useMemo<ToastApi>(() => ({
    show,
    success: (message) => show(message, 'success'),
    error: (message) => show(message, 'error'),
  }), [show])

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div className="toast-region" role="region" aria-label={m.toast.region} aria-live="polite" aria-atomic="false">
        {toasts.map((toast) => (
          <div className={`toast toast-${toast.tone}`} key={toast.id}>
            <Icon name={toast.tone === 'error' ? 'shield' : toast.tone === 'success' ? 'check' : 'sparkle'} size={16} />
            <span className="toast-message">{toast.message}</span>
            <button type="button" className="toast-dismiss" onClick={() => dismiss(toast.id)} aria-label={m.toast.dismiss}>
              <Icon name="close" size={14} />
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}
