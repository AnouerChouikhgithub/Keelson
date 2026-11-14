import type { Messages } from '../i18n/messages'
import type { AppNotification } from '../mocks/notifications'

export function notificationText(notification: AppNotification, m: Messages): string {
  const { number = '', customer = '', email = '', name = '' } = notification.params
  switch (notification.kind) {
    case 'invoicePaid': return m.notifications.types.invoicePaid(number, customer)
    case 'invoiceOverdue': return m.notifications.types.invoiceOverdue(number, customer)
    case 'invoiceSent': return m.notifications.types.invoiceSent(number, customer)
    case 'invitationAccepted': return m.notifications.types.invitationAccepted(email)
    case 'invitationPending': return m.notifications.types.invitationPending(email)
    case 'memberJoined': return m.notifications.types.memberJoined(name)
    case 'security': return m.notifications.types.security
  }
}
