import { request } from './client'
import type { ProfileUser } from './types'

/** `PATCH /api/me` — the display name is the only mutable profile field. */
export async function updateProfile(displayName: string): Promise<ProfileUser> {
  const payload = await request<{ user: ProfileUser }>('/api/me', {
    method: 'PATCH',
    body: { displayName },
  })
  return payload.user
}

/**
 * `POST /api/me/password` — keeps this session and signs every other one out.
 *
 * A wrong current password is a `422` with a `currentPassword` field, which the
 * account screen shows on that input rather than as a form-level failure.
 */
export function changePassword(currentPassword: string, newPassword: string): Promise<void> {
  return request<void>('/api/me/password', {
    method: 'POST',
    body: { currentPassword, newPassword },
  })
}
