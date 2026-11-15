import { useI18n } from '../i18n/I18nContext'
import { clearSessionEnded } from '../session/session'

/**
 * Shown only after the server confirms the session has ended. The sign-in
 * link is a full navigation to the Symfony entry point, which starts the OIDC
 * redirect — the SPA never fakes a local-only sign-in.
 */
export function SignedOutScreen() {
  const { m } = useI18n()

  return (
    <main className="signed-out-screen">
      <section className="panel signed-out-card">
        <img src="/Keelson.svg" alt="" width={34} height={34} />
        <h1>{m.session.signedOutTitle}</h1>
        <p>{m.session.signedOutBody}</p>
        {/* Full navigation to the OIDC entry point; clearing the tab's sign-out
            marker first keeps the post-login return from flashing this screen. */}
        <a className="button button-primary" href="/login" onClick={clearSessionEnded}>{m.session.signIn}</a>
      </section>
    </main>
  )
}
