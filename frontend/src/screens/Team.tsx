import { BackendGate } from '../components/BackendGate'
import { DataTable } from '../components/DataTable'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { useI18n } from '../i18n/I18nContext'
import { useAuth } from '../session/AuthContext'
import { useWorkspace } from '../app/WorkspaceContext'
import './simple.css'

/**
 * The caller's own membership is real data, so it is shown. Everything else on
 * a team screen — the member list, role changes, removal, leaving, invitations
 * — needs endpoints this build does not have, so it is gated instead of faked.
 */
export default function Team() {
  const { m } = useI18n()
  const { user } = useAuth()
  const { membership } = useWorkspace()
  useDocumentTitle(m.page.team)

  const name = user?.displayName ?? user?.email ?? m.account.unknownIdentity
  const roleLabel = m.roles[membership.role.toLowerCase() as keyof typeof m.roles]

  return (
    <div className="simple-page">
      <section className="page-intro">
        <div>
          <p className="eyebrow">{m.nav.workspace}</p>
          <h1>{m.team.title}</h1>
          <p>{m.team.subtitle}</p>
        </div>
      </section>

      <section className="panel" aria-labelledby="your-membership-title">
        <div className="panel-heading">
          <div>
            <h2 id="your-membership-title">{m.team.yourTitle}</h2>
            <p>{m.team.yourHint}</p>
          </div>
        </div>
        <DataTable caption={m.team.tableCaption}>
          <thead>
            <tr>
              <th scope="col">{m.team.columns.name}</th>
              <th scope="col">{m.team.columns.email}</th>
              <th scope="col">{m.team.columns.role}</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td className="cell-strong">{name} <span className="you-tag">{m.team.you}</span></td>
              <td>{user?.email ?? '—'}</td>
              <td>{roleLabel}</td>
            </tr>
          </tbody>
        </DataTable>
        <p className="role-note">{m.team.roleNote(roleLabel)}</p>
      </section>

      <BackendGate
        title={m.team.gateTitle}
        body={m.team.gateBody}
        endpoint={m.team.gateEndpoint}
        backTo={`/o/${membership.id}/dashboard`}
      >
        <p>{m.team.lastOwnerNote}</p>
      </BackendGate>
    </div>
  )
}
