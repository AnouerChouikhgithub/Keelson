import { Link } from 'react-router'
import type { OrganizationRole } from '../../api/types'
import { Icon } from '../../components/Icon'
import { useI18n } from '../../i18n/I18nContext'
import { useAiStatus } from './useAiStatus'
import './ai.css'

/**
 * The one line an Owner or Admin sees while AI is available but not opted in.
 *
 * Every other AI surface stays hidden until the workspace opts in, which is the
 * brief — but an Owner who has never opened Settings would then never learn the
 * feature exists at all. This is the single, quiet exception: one sentence and a
 * link to the setting that turns it on.
 *
 * Members and Viewers see nothing, because they cannot change it, and the server
 * answers `404` for them anyway. Nothing renders when the deployment switch is
 * off or `/api/ai/status` failed — the same absence as everywhere else, so the
 * hint cannot reveal that AI exists on a deployment that disabled it.
 */
export function AiOptInHint({ organizationId, role }: { organizationId: string; role: OrganizationRole }) {
  // Checked before the status read: a member has nothing to act on, so the
  // component makes no request at all for them.
  if (role !== 'Owner' && role !== 'Admin') return null

  return <OptInStatus organizationId={organizationId} />
}

function OptInStatus({ organizationId }: { organizationId: string }) {
  const { m } = useI18n()
  const status = useAiStatus(organizationId)

  // `enabled` is the deployment switch, `organizationEnabled` the opt-in this
  // hint exists to offer. Narrowing on `state` also covers loading and failure.
  if (status.state !== 'ready' || !status.enabled || status.organizationEnabled) return null

  return (
    <p className="ai-optin-hint" role="note">
      <Icon name="sparkle" size={16} />
      <span>
        {m.ai.optIn.available}{' '}
        <Link to={`/o/${organizationId}/settings#ai-settings`}>{m.ai.optIn.action}</Link>
      </span>
    </p>
  )
}
