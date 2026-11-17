export const common = {
  languageName: 'Français',
  skipToContent: 'Aller au contenu',
  loading: 'Chargement…',
  loadingMore: 'Chargement…',
  retry: 'Réessayer',
  reload: 'Recharger',
  cancel: 'Annuler',
  close: 'Fermer',
  dismiss: 'Fermer la notification',
  save: 'Enregistrer',
  saving: 'Enregistrement…',
  create: 'Créer',
  edit: 'Modifier',
  delete: 'Supprimer',
  back: 'Retour',
  search: 'Rechercher',
  clearSearch: 'Effacer la recherche',
  loadMore: 'Charger plus',
  loadedCount: (count: number) => (count <= 1 ? `${count} élément chargé` : `${count} éléments chargés`),
  actions: 'Actions',
  print: 'Imprimer',
  status: 'Statut',
  amount: 'Montant',
  name: 'Nom',
  email: 'E-mail',
  phone: 'Téléphone',
  role: 'Rôle',
  organization: 'Organisation',
  sessionExpired: 'Votre session a changé. Reconnectez-vous.',
}

export const nav = {
  workspace: 'ESPACE DE TRAVAIL',
  mainNav: 'Navigation principale',
  breadcrumb: 'Fil d’Ariane',
  dashboard: 'Tableau de bord',
  invoices: 'Factures',
  customers: 'Clients',
  team: 'Équipe',
  audit: 'Journal d’audit',
  settings: 'Paramètres',
  account: 'Mon compte',
  notifications: 'Notifications',
  toggleNav: 'Afficher ou masquer la navigation',
  closeNav: 'Fermer la navigation',
  currentOrg: (name: string) => `Organisation actuelle : ${name}`,
  switchOrg: 'Changer d’organisation',
  yourWorkspaces: 'VOS ESPACES',
  createOrg: 'Créer une organisation',
  menuFootnote: 'L’URL désigne l’espace ; le serveur réautorise chaque requête.',
  openProfileMenu: 'Ouvrir le menu du profil',
  profileMenuLabel: 'Menu du compte',
}

export const page = {
  dashboard: 'Tableau de bord',
  invoices: 'Factures',
  newInvoice: 'Nouvelle facture',
  invoice: 'Facture',
  customers: 'Clients',
  team: 'Équipe',
  audit: 'Journal d’audit',
  settings: 'Paramètres',
  account: 'Mon compte',
  notifications: 'Notifications',
  onboarding: 'Intégration',
  notFound: 'Page introuvable',
}

export const theme = {
  appearance: 'Apparence',
  light: 'Clair',
  dark: 'Sombre',
  system: 'Système',
  setTheme: (label: string) => `Thème ${label.toLowerCase()}`,
}

export const search = {
  title: 'Rechercher dans Keelson',
  placeholder: 'Rechercher une page ou une organisation…',
  open: 'Ouvrir la recherche',
  close: 'Fermer la recherche',
  shortcutHint: (keys: string) => `Appuyez sur ${keys} pour ouvrir la recherche`,
  noResults: (query: string) => `Aucun résultat pour « ${query} »`,
  noResultsHint: 'Essayez un nom de page ou l’une de vos organisations.',
  resultCount: (count: number) => (count <= 1 ? `${count} résultat` : `${count} résultats`),
  hint: 'Utilisez les flèches pour vous déplacer, Entrée pour ouvrir.',
  kinds: {
    page: 'Page',
    organization: 'Organisation',
  },
}

export const api = {
  loading: 'Chargement…',
  offline: 'Le serveur est injoignable. Démarrez l’API, vérifiez votre connexion, puis réessayez.',
  timeout: 'Le serveur met trop de temps à répondre. Réessayez.',
  unauthorized: 'Votre session n’est plus valide. Reconnectez-vous pour continuer.',
  forbidden: 'Votre rôle dans cet espace ne permet pas cette action.',
  notFound: 'Cet élément n’existe plus.',
  conflict: 'L’élément a changé sur le serveur. La dernière version a été chargée.',
  invalid: 'Le serveur a refusé certaines valeurs.',
  rateLimited: 'Trop de requêtes. Attendez un instant puis réessayez.',
  server: 'Le serveur n’a pas pu traiter la requête.',
  unexpected: 'Le serveur a renvoyé une réponse inattendue.',
  listFailed: 'Cette liste n’a pas pu être chargée.',
}

export const profile = {
  myAccount: 'Mon compte',
  notifications: 'Notifications',
  settings: 'Paramètres',
  logout: 'Se déconnecter',
}

export const session = {
  cardTitle: 'Session',
  signingOut: 'Déconnexion…',
  readFailed: 'Impossible de lire la session sur le serveur. Démarrez l’API, puis réessayez.',
}

export const status = {
  paid: 'Payée',
  sent: 'Envoyée',
  overdue: 'En retard',
  draft: 'Brouillon',
}

export const roles = {
  owner: 'Propriétaire',
  admin: 'Admin',
  member: 'Membre',
  viewer: 'Lecteur',
}

export const notFound = {
  eyebrow: '404',
  title: 'Cette page n’existe pas',
  body: 'L’adresse est peut-être mal saisie, ou l’élément a été supprimé. Choisissez un espace dans le sélecteur pour continuer.',
  backToDashboard: 'Aller au tableau de bord',
}

export const gate = {
  eyebrow: 'NON CONNECTÉ',
  endpointLabel: 'Point d’accès manquant',
  sourceNote: 'Aucune donnée de démonstration n’est affichée ici.',
  backToDashboard: 'Retour au tableau de bord',
}

export const toast = {
  region: 'Messages d’état',
  dismiss: 'Fermer la notification',
}

export const errors = {
  boundaryTitle: 'Cet écran ne répond plus',
  boundaryBody:
    'Une erreur inattendue du client a été interceptée. Vos données sont sur le serveur ; rechargez l’écran pour continuer.',
  boundaryReload: 'Recharger l’écran',
  title: 'Une erreur est survenue',
}
