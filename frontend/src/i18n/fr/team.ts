export const team = {
  title: 'Équipe',
  subtitle: 'Membres, rôles et invitations de cet espace.',
  yourTitle: 'Votre adhésion',
  yourHint: 'Lue depuis votre session et les adhésions renvoyées par l’API.',
  tableCaption: 'Votre adhésion à cet espace',
  columns: {
    name: 'Membre',
    email: 'E-mail',
    role: 'Rôle',
  },
  you: 'Vous',
  roleNote: (role: string) => `Vous êtes ${role} dans cet espace. Le serveur décide de chaque requête ; masquer une action n’est qu’un confort.`,
  gateTitle: 'La gestion d’équipe n’est pas connectée',
  gateBody:
    'L’API n’a aucun point d’accès pour lister les membres, changer un rôle, retirer un membre, quitter une organisation ou émettre des invitations. Aucun membre fictif n’est affiché à la place.',
  gateEndpoint: 'GET/PATCH/DELETE /api/organizations/{organizationId}/members',
  lastOwnerNote:
    'Les règles de propriété, comme « le dernier propriétaire ne peut pas être retiré », ne pourront être appliquées qu’avec ce point d’accès.',
}

export const notifications = {
  title: 'Notifications',
  subtitle: 'Activité des factures, de l’équipe et du compte dans cet espace.',
  gateTitle: 'Les notifications ne sont pas connectées',
  gateBody:
    'L’API n’a ni fil de notifications ni état de lecture : l’en-tête n’affiche donc aucun badge et cet écran ne montre aucune notification fictive.',
  gateEndpoint: 'GET /api/notifications et POST /api/notifications/{id}/read',
}
