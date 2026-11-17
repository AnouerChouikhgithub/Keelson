/**
 * Team and invitations are gated: this build has no membership or invitation
 * endpoint. The caller's own membership is real data and is shown as such.
 */
export const team = {
  title: 'Team',
  subtitle: 'Members, roles and invitations in this workspace.',
  yourTitle: 'Your membership',
  yourHint: 'Read from your session and the memberships the API returned.',
  tableCaption: 'Your membership in this workspace',
  columns: {
    name: 'Member',
    email: 'Email',
    role: 'Role',
  },
  you: 'You',
  roleNote: (role: string) => `You are ${role} in this workspace. The server decides every request; hiding an action is only a convenience.`,
  gateTitle: 'Team management is not connected',
  gateBody:
    'The API has no endpoints for listing members, changing a role, removing a member, leaving an organization or issuing invitations. No demo members are shown in their place.',
  gateEndpoint: 'GET/PATCH/DELETE /api/organizations/{organizationId}/members',
  lastOwnerNote: 'Owner rules such as “the last owner cannot be removed” can only be enforced once that endpoint exists.',
}

export const notifications = {
  title: 'Notifications',
  subtitle: 'Invoice, team and account activity in this workspace.',
  gateTitle: 'Notifications are not connected',
  gateBody:
    'The API has neither a notifications feed nor read state, so the header has no unread badge and this screen shows no demo notifications.',
  gateEndpoint: 'GET /api/notifications and POST /api/notifications/{id}/read',
}
