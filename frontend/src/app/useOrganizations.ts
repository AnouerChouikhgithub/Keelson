import { useCallback } from 'react'
import { listOrganizations } from '../api/organizations'
import { useApiResource } from '../api/useApiResource'

/**
 * The caller's memberships, read once per session shape and shared through the
 * resource cache. The shell and the organization scope use the same key, so a
 * route change does not refetch the list.
 */
export function useOrganizations() {
  const load = useCallback((signal: AbortSignal) => listOrganizations(signal), [])
  return useApiResource('organizations', load)
}
