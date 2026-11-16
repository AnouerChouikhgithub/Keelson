import { Link } from 'react-router'
import { presentError } from '../api/presentError'
import { BackendGate } from '../components/BackendGate'
import { DataTable } from '../components/DataTable'
import { EmptyState } from '../components/EmptyState'
import { ErrorState } from '../components/ErrorState'
import { Skeleton } from '../components/Skeleton'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { useOrganizations } from '../app/useOrganizations'
import './simple.css'

/**
 * Organizations the caller already belongs to are real and listed. Creating one
 * — and the live subdomain availability check that belongs with it — needs
 * endpoints this build does not ship, so the form is replaced by a gate.
 */
export default function Onboarding() {
  const { m } = useI18n()
  const organizations = useOrganizations()
  useDocumentTitle(m.page.onboarding)

  return (
    <div className="simple-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.onboarding.eyebrow}</p>
          <h1>{m.onboarding.title}</h1>
          <p>{m.onboarding.subtitle}</p>
        </div>
      </section>

      <section className="panel" aria-labelledby="your-organizations-title">
        <div className="panel-heading">
          <div>
            <h2 id="your-organizations-title">{m.onboarding.yours}</h2>
            <p>{m.onboarding.yoursHint}</p>
          </div>
        </div>

        {organizations.state.status === 'loading' && <Skeleton variant="list" count={3} label={m.common.loading} />}
        {organizations.state.status === 'error' && (
          <ErrorState
            title={m.api.listFailed}
            message={presentError(m, organizations.state.error)}
            retryLabel={m.common.retry}
            onRetry={organizations.reload}
          />
        )}
        {organizations.state.status === 'ready' && organizations.state.data.length === 0 && (
          <EmptyState icon="building" title={m.account.noWorkspace} body={m.account.noWorkspaceHint} />
        )}
        {organizations.state.status === 'ready' && organizations.state.data.length > 0 && (
          <DataTable caption={m.onboarding.yours}>
            <thead>
              <tr>
                <th scope="col">{m.common.organization}</th>
                <th scope="col">{m.settings.subdomain}</th>
                <th scope="col">{m.common.role}</th>
                <th scope="col"><span className="visually-hidden">{m.common.actions}</span></th>
              </tr>
            </thead>
            <tbody>
              {organizations.state.data.map((organization) => (
                <tr key={organization.id}>
                  <td className="cell-strong">{organization.name}</td>
                  <td>{organization.subdomain}.keelson.app</td>
                  <td>{m.roles[organization.role.toLowerCase() as keyof typeof m.roles]}</td>
                  <td className="cell-end">
                    <Link className="button button-ghost button-sm" to={`/o/${organization.id}/dashboard`}>
                      {m.onboarding.open}
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        )}
      </section>

      <BackendGate
        title={m.onboarding.gateTitle}
        body={m.onboarding.gateBody}
        endpoint={m.onboarding.gateEndpoint}
      >
        <div className="gate-fields">
          <p className="gate-field"><span>{m.onboarding.formTitle}</span></p>
          <p className="gate-field">{m.onboarding.orgName} · {m.onboarding.address}</p>
          <p className="gate-field">{m.onboarding.addressUnverified}</p>
        </div>
      </BackendGate>
    </div>
  )
}
