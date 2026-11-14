export type CustomerRecord = {
  id: string
  name: string
  initials: string
  email: string
  phone: string
  city: string
  address: string
  invoiceCount: number
  billed: number
  lastInvoice: string
}

/** Customer fixtures for the Customers screen; amounts are TND. */
export const customerRecords: CustomerRecord[] = [
  {
    id: 'c-mj', name: 'Maison Jasmin', initials: 'MJ', email: 'facturation@maisonjasmin.tn', phone: '+216 71 245 180',
    city: 'Tunis', address: '12 rue de Marseille, 1000 Tunis', invoiceCount: 14, billed: 32400, lastInvoice: '2026-10-22',
  },
  {
    id: 'c-ea', name: 'Société El Amal', initials: 'EA', email: 'compta@elamal.tn', phone: '+216 73 402 116',
    city: 'Sfax', address: '8 avenue Habib Bourguiba, 3000 Sfax', invoiceCount: 9, billed: 18750, lastInvoice: '2026-10-20',
  },
  {
    id: 'c-cs', name: 'Carthage Studio', initials: 'CS', email: 'hello@carthagestudio.tn', phone: '+216 71 728 004',
    city: 'La Marsa', address: '27 rue Ibn Khaldoun, 2070 La Marsa', invoiceCount: 6, billed: 9100, lastInvoice: '2026-10-18',
  },
  {
    id: 'c-lo', name: 'Les Oliviers', initials: 'LO', email: 'contact@lesoliviers.tn', phone: '+216 72 511 933',
    city: 'Sousse', address: '5 boulevard du 14 Janvier, 4000 Sousse', invoiceCount: 11, billed: 26300, lastInvoice: '2026-10-15',
  },
  {
    id: 'c-bm', name: 'Bizerte Marine', initials: 'BM', email: 'admin@bizertemarine.tn', phone: '+216 72 431 580',
    city: 'Bizerte', address: '19 quai Tarak Ibn Ziad, 7000 Bizerte', invoiceCount: 4, billed: 11200, lastInvoice: '2026-10-02',
  },
  {
    id: 'c-st', name: 'Sfax Textiles', initials: 'ST', email: 'finance@sfaxtextiles.tn', phone: '+216 74 228 641',
    city: 'Sfax', address: '31 route de Gabès, 3027 Sfax', invoiceCount: 7, billed: 21040, lastInvoice: '2026-09-20',
  },
]
