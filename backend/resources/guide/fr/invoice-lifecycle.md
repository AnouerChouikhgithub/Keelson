---
id: invoice-lifecycle
title: Factures
roles: []
keywords: ["facture","créer facture","nouvelle facture","brouillon","envoyer facture","marquer payée","en retard","échéance","numéro de facture","modifier facture","supprimer facture"]
---

Une facture commence en brouillon : un client, une date d'émission, une échéance identique ou postérieure, et une ou plusieurs lignes avec description, quantité et prix unitaire. Le taux de taxe et la remise sont facultatifs. Le serveur calcule le sous-total, la taxe, la remise et le total ; la devise est le TND.

Les numéros sont attribués par organisation et par année : la première facture de 2026 est `2026-000001`. L'attribution est sérialisée, ce qui évite que deux créations simultanées reçoivent le même numéro.

Brouillon : Owner, Admin et Member peuvent le modifier ; seuls Owner et Admin peuvent le supprimer. Envoyer : le statut devient `sent`. Marquer payée : possible depuis `sent` ou `overdue`, et cela notifie le reste de l'administration.

« En retard » est déduit, non stocké : une facture envoyée dont l'échéance est passée se lit comme en retard, et une lecture n'écrit jamais le statut.

Owner, Admin et Member peuvent créer, modifier, envoyer et marquer payée ; seuls Owner et Admin suppriment un brouillon. Un Viewer voit la liste et le détail. Chaque changement ajoute un événement d'audit dans la même transaction.
