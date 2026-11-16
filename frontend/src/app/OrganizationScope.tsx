import { useMemo } from 'react'
import { Link, Outlet, useParams } from 'react-router'
import { presentError } from '../api/presentError'
import { ErrorState } from '../components/ErrorState'
import { Skeleton } from '../components/Skeleton'
import { useI18n } from '../i18n/I18nContext'
import { WorkspaceContext } from './WorkspaceContext'
import { useOrganizations } from './useOrganizations'
import './OrganizationScope.css'

/**
 * Resolves `/o/:orgId` against the caller's memberships before any screen runs.
 *
 * A tenant the caller does not belong to is indistinguishable from one that
 * does not exist — the same answer the API gives — so both render one honest
 * state with no data request behind it.
 */
export function OrganizationScope() {
  const { orgId } = useParams<{ orgId: string }>()
  const { m } = useI18n()
  const organizations = useOrganizations()

  const value = useMemo(() => {
    if (organizations.state.status !== 'ready') return null
    const membership = organizations.state.data.find((item) => item.id === orgId)
    if (!membership) return null
    return {
      membership,
      memberships: organizations.state.data,
      refreshMemberships: organizations.reload,
    }
  }, [organizations.state, organizations.reload, orgId])

  if (organizations.state.status === 'loading') {
    return <Skeleton variant="list" count={4} label={m.common.loading} />
  }

  if (organizations.state.status === 'error') {
    return (
      <ErrorState
        title={m.api.listFailed}
        message={presentError(m, organizations.state.error)}
        retryLabel={m.common.retry}
        onRetry={organizations.reload}
      />
    )
  }

  if (value === null) {
    return (
      <section className="org-denied" aria-labelledby="org-denied-title">
        <p className="org-denied-eyebrow">{m.notFound.eyebrow}</p>
        <h1 id="org-denied-title">{m.notFound.title}</h1>
        <p>{m.notFound.body}</p>
        <Link className="button button-primary" to="/">{m.notFound.backToDashboard}</Link>
      </section>
    )
  }

  return (
    <WorkspaceContext.Provider value={value}>
      <Outlet />
    </WorkspaceContext.Provider>
  )
}
