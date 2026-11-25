---
id: team-and-roles
title: Équipe et rôles
roles: []
keywords: ["inviter","invitation","collègue","membre","équipe","ajouter membre","inviter quelqu'un","rôles","permissions","propriétaire","administrateur","lecteur","viewer","lecture seule","quitter l'organisation","rétrograder","admin","member","owner","retrograder","retrograde","dernier owner"]
---

Il existe quatre rôles, et chaque requête revérifie l'appartenance : un changement s'applique immédiatement.

- Owner : tout, y compris renommer ou supprimer l'organisation et gérer n'importe quel membre. Le dernier Owner ne peut être rétrogradé ni retiré ; la requête répond `409 last_owner`.
- Admin : la liste des membres, les invitations et les paramètres IA. Un Admin ne peut gérer que les Member et les Viewer et n'inviter qu'en tant que Member ou Viewer : la frontière des privilèges ne peut pas être élargie par le bas. Renommer l'organisation, changer son adresse et la supprimer restent réservés à l'Owner.
- Member : lit tout et crée ou modifie clients et factures.
- Viewer : lit tout et n'écrit rien.

Invitez depuis l'écran Équipe avec une adresse et un rôle. Une invitation expire après 7 jours ; la renvoyer émet un nouveau lien et invalide le précédent, au plus cinq fois par invitation et par minute. L'acceptation exige d'être connecté avec l'adresse invitée, ou de choisir un mot de passe si aucun compte n'existe encore.

Tout membre peut retirer sa propre appartenance pour quitter l'organisation, sauf le dernier Owner.
