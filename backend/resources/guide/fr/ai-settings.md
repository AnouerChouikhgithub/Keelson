---
id: ai-settings
title: Paramètres IA
roles: [Owner, Admin]
keywords: ["paramètres ia","activer ia","budget","limites","limite de requêtes","ia désactivée","opt in","registre","prompt","reponse","stocke","journal"]
---

L'IA est désactivée tant qu'un Owner ou un Admin ne l'active pas pour l'organisation. Ouvrez les paramètres, section IA : l'interrupteur et le budget mensuel en jetons (0 à 100 000 000) sont tous deux obligatoires. Tout autre rôle, Member compris, reçoit « introuvable » sur ces paramètres.

Passer l'interrupteur de désactivé à activé enregistre une ligne `security_events` de type `ai_enabled` ; le désactiver n'en écrit pas, car retirer une capacité n'en crée pas une.

Une requête est refusée avant d'atteindre un fournisseur si l'IA est désactivée (`403 ai_disabled`), si la réservation dépasserait le budget mensuel (`402 ai_budget_exceeded`), ou si une limite de débit est atteinte (`429 ai_rate_limited`, avec `Retry-After`). Les limites sont 20 requêtes par 5 minutes et par personne, et 200 par 5 minutes et par organisation : un onglet ne peut pas boucler et un tenant ne peut pas épuiser son budget d'un coup.

Le registre conserve la fonctionnalité, le statut, le modèle, les jetons et la latence. Il ne stocke jamais un prompt ni une réponse.
