import { Link } from 'react-router'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import './simple.css'

export default function NotFound() {
  const { m } = useI18n()
  useDocumentTitle(m.page.notFound)

  return (
    <section className="simple-page">
      <p className="eyebrow">{m.notFound.eyebrow}</p>
      <h1>{m.notFound.title}</h1>
      <p className="panel-help">{m.notFound.body}</p>
      <div>
        <Link className="button button-primary" to="/">{m.notFound.backToDashboard}</Link>
      </div>
    </section>
  )
}
