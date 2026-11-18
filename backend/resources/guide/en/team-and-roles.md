---
id: team-and-roles
title: Team and roles
roles: []
---

Four roles exist, and every request re-checks the membership, so a change applies immediately.

- Owner: everything, including renaming or deleting the organization and managing any member. The last Owner cannot be demoted or removed; that request answers `409 last_owner`.
- Admin: the member list, invitations and the AI settings. An Admin may only manage Members and Viewers and may only invite as Member or Viewer, so the privilege boundary cannot be widened from below. Renaming the organization, changing its address and deleting it stay Owner-only.
- Member: reads everything and creates or edits customers and invoices.
- Viewer: reads everything and writes nothing.

Invite from the team screen with an address and a role. An invitation expires after 7 days; resending issues a new link and retires the previous one, at most five times per invitation per minute. Accepting requires signing in as the invited address, or choosing a password when the address has no account yet.

Any member may remove their own membership to leave, except the last Owner.
