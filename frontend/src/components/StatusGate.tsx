import { useI18n } from '../i18n/I18nContext'
import '../screens/auth/auth.css'
import './Button.css'

/**
 * Shown while the session or the caller's memberships cannot be read.
 *
 * It never falls back to demo identity or demo records, and it always offers a
 * retry: the API being down is a state to recover from, not a signed-out user.
 */
export function StatusGate({ title, message, onRetry }: { title: string; message: string; onRetry: () => void }) {
  const { m } = useI18n()

  return (
    <main className="auth-screen">
      <section className="auth-card">
        <div className="auth-brand">
          <img src="/Keelson.svg" alt="" width={34} height={34} />
          <span>keelson</span>
        </div>
        <h1>{title}</h1>
        <p className="auth-subtitle">{message}</p>
        <button type="button" className="button button-secondary auth-submit" onClick={onRetry}>
          {m.common.reload}
        </button>
      </section>
    </main>
  )
}
