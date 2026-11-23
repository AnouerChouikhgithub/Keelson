import { useCallback } from 'react'
import { useApiResource } from '../../api/useApiResource'
import { getAiStatus } from './api'

export type AiStatusState =
  | { state: 'loading' }
  | { state: 'ready'; enabled: boolean; organizationEnabled: boolean; budgetRemainingTokens: number }
  /** The call failed or was refused: AI surfaces render nothing at all. */
  | { state: 'unavailable' }

/**
 * Reads `GET /api/ai/status` for one workspace.
 *
 * Failure is deliberately not an error state with a retry button: the brief
 * requires those surfaces to disappear rather than report a problem, so a
 * failed status read is indistinguishable from AI being off. The read is cached
 * by `useApiResource` under the workspace, so the header button, the dashboard
 * cards and the settings section share one request.
 */
export function useAiStatus(organizationId: string): AiStatusState {
  const load = useCallback((signal: AbortSignal) => getAiStatus(organizationId, signal), [organizationId])
  const resource = useApiResource(`ai-status:${organizationId}`, load)

  if (resource.state.status === 'ready') {
    const { enabled, organizationEnabled, budgetRemainingTokens } = resource.state.data
    return { state: 'ready', enabled, organizationEnabled, budgetRemainingTokens }
  }
  if (resource.state.status === 'error') return { state: 'unavailable' }
  return { state: 'loading' }
}

/**
 * True when this workspace may actually spend AI: the deployment enables it and
 * the organization opted in. Anything else (including a failed read) is "off".
 */
export function canUseAi(status: AiStatusState): boolean {
  return status.state === 'ready' && status.enabled && status.organizationEnabled
}

/** True when the deployment enables AI at all, so an admin may still opt in. */
export function canConfigureAi(status: AiStatusState): boolean {
  return status.state === 'ready' && status.enabled
}
