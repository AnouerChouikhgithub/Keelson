import { describe, expect, it } from 'vitest'

import { ai as ar } from './ar/ai'
import { ai as en } from './en/ai'
import { ai as fr } from './fr/ai'

/**
 * The assistant reads no tenant data: only the product guide and the caller's role.
 * The panel used to say the opposite in all three languages, so this guards the exact
 * sentences (not a keyword grep): a translation that reintroduces "workspace data"
 * must fail here before it reaches a user.
 */
describe('assistant privacy copy', () => {
  it('never claims the assistant reads workspace data', () => {
    const intros = [en.assistant.intro, fr.assistant.intro, ar.assistant.intro]

    expect(intros).toEqual([
      'Answers come from Keelson’s product guide and your role. The assistant never reads this workspace’s data.',
      'Les réponses proviennent du guide du produit Keelson et de votre rôle. L’assistant ne lit jamais les données de cet espace.',
      'تأتي الإجابات من دليل منتج كيلسون ومن دورك. لا يقرأ المساعد بيانات مساحة العمل هذه أبدًا.',
    ])
  })

  it('tells the Owner which fields a summary sends, including customer names', () => {
    for (const consent of [en.settings.consent, fr.settings.consent, ar.settings.consent]) {
      expect(consent.length).toBeGreaterThan(200)
    }

    expect(en.settings.consent).toContain('up to five customer display names')
    expect(fr.settings.consent).toContain('cinq noms d’affichage de clients')
    expect(ar.settings.consent).toContain('خمسة أسماء عرض للعملاء')
  })
})
