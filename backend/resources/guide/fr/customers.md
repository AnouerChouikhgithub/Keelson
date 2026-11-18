---
id: customers
title: Clients
roles: []
---

Un client appartient à une seule organisation et n'est accessible que par elle : l'identifiant d'un client d'une autre organisation répond « introuvable ».

Créez un client avec un nom ; l'e-mail, le téléphone et l'adresse de facturation sont facultatifs. Owner, Admin et Member peuvent créer, modifier et supprimer ; un Viewer lit seulement.

La liste accepte `q`, qui cherche dans le nom ou l'e-mail sans tenir compte de la casse, et une pagination par curseur, 25 lignes par page par défaut (`limit` jusqu'à 100). Le curseur est la dernière ligne reçue, pas un décalage : un client créé pendant la navigation ne décale pas la fenêtre.

La suppression est refusée tant qu'une facture référence le client (`409`, « Customer is referenced by invoices »). La ligne est verrouillée pendant la vérification, afin qu'une facture créée au même instant ne passe pas.

Chaque création, modification et suppression écrit un événement d'audit dans la même transaction. Une facture conserve un instantané des coordonnées du client : renommer un client plus tard ne réécrit pas une facture émise.
