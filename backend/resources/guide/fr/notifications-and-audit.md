---
id: notifications-and-audit
title: Notifications et journal d'audit
roles: []
keywords: ["notification","boîte de réception","non lu","marquer lu","audit","journal d'audit","historique","activité"]
---

Les notifications sont personnelles, pas organisationnelles : votre boîte reçoit les événements de toutes les organisations dont vous êtes membre, et vous seul pouvez les lire ou les marquer. Les quatre types sont `invoice_paid` (quelqu'un d'autre a enregistré le paiement), `invoice_overdue`, `member_joined` et `invitation_accepted`.

La liste renvoie 25 lignes par défaut (`limit` jusqu'à 100), les plus récentes d'abord, avec un curseur pour la page suivante ; `unread=1` ne renvoie que le non-lu. Marquer une notification comme lue, ou toutes, ne change que votre propre état de lecture.

Le journal d'audit est l'historique de l'organisation. Tout membre actif peut lire `/api/organizations/{id}/invoice-audit-events`, filtré par `invoiceId`, recherché avec `q`, paginé par curseur comme les autres listes. Les actions enregistrées sont `customer.created`, `customer.updated`, `customer.deleted`, `invoice.created`, `invoice.updated`, `invoice.sent`, `invoice.paid` et `invoice.deleted`, avec l'acteur, l'identifiant de facture ou de client et l'horodatage.

La table est en ajout seul : un déclencheur refuse les mises à jour et suppressions, une ligne d'audit ne peut donc être ajoutée, jamais réécrite.
