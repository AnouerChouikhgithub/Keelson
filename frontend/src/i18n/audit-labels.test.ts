import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { messages } from '.'

/**
 * Every audit action the backend can record has a label in all three languages.
 *
 * The list is not copied here: it is read from
 * `backend/src/Audit/InvoiceAuditAction.php`, the closed set the controller, the
 * fixture loader and the bulk command all write through. A new constant in that
 * file therefore fails this test until en, fr and ar carry its label — which is
 * the point, because the alternate would ship a raw `invoice.something` to a user
 * in three languages and nobody would notice.
 */
const ACTIONS_SOURCE = fileURLToPath(new URL('../../../backend/src/Audit/InvoiceAuditAction.php', import.meta.url))

function backendActions(): string[] {
  const php = readFileSync(ACTIONS_SOURCE, 'utf8')
  const actions: string[] = []
  for (const match of php.matchAll(/public const [A-Z_]+ = '([^']+)'/g)) {
    actions.push(match[1])
  }
  return actions
}

describe('audit labels', () => {
  it('reads the closed action set from the backend', () => {
    const actions = backendActions()
    // A rename or a moved file must fail loudly rather than silently checking nothing.
    expect(actions.length).toBeGreaterThan(0)
    expect(actions).toContain('invoice.sent')
  })

  it.each(['en', 'fr', 'ar'] as const)('labels every action in %s', (locale) => {
    const labels = messages[locale].invoices.audit.actions
    for (const action of backendActions()) {
      expect(labels[action], `${locale} has no label for "${action}"`).toMatch(/\S/)
    }
  })

  it('does not carry a label for an action the backend cannot emit', () => {
    const actions = new Set(backendActions())
    for (const [key, labels] of Object.entries({
      en: messages.en.invoices.audit.actions,
      fr: messages.fr.invoices.audit.actions,
      ar: messages.ar.invoices.audit.actions,
    })) {
      for (const action of Object.keys(labels)) {
        expect(actions.has(action), `${key} labels "${action}", which no backend constant declares`).toBe(true)
      }
    }
  })
})
