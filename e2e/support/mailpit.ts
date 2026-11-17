import { expect } from '@playwright/test'

/**
 * Reads the messages Mailpit captured, through its HTTP API.
 *
 * The stack mails every verification, reset and invitation link to Mailpit, so
 * a journey can follow the same link a person would click. Nothing here parses
 * the database: if the mail were not sent, the test fails.
 */
const mailpitUrl = process.env.MAILPIT_URL ?? 'http://127.0.0.1:8026'

type MailpitAddress = { Address: string }

type MailpitMessage = {
  ID: string
  Subject: string
  To: MailpitAddress[]
}

/** The newest message addressed to `address`, or null while none has arrived. */
async function latestMessageTo(address: string): Promise<MailpitMessage | null> {
  const response = await fetch(`${mailpitUrl}/api/v1/messages?limit=100`)
  if (!response.ok) throw new Error(`Mailpit answered ${response.status}; is it running on ${mailpitUrl}?`)
  const body = (await response.json()) as { messages?: MailpitMessage[] }
  const wanted = address.toLowerCase()
  return (body.messages ?? []).find((message) => message.To.some((to) => to.Address.toLowerCase() === wanted)) ?? null
}

/**
 * The path and query of the first link to `path` in a message.
 *
 * Returns a same-origin path rather than the absolute URL from the mail, so the
 * journey always runs against the SPA origin under test even if FRONTEND_URL
 * names a different host or port.
 */
async function linkInMessage(id: string, path: string): Promise<string> {
  const response = await fetch(`${mailpitUrl}/api/v1/message/${id}`)
  if (!response.ok) throw new Error(`Mailpit answered ${response.status} for message ${id}.`)
  const body = (await response.json()) as { HTML?: string; Text?: string }
  const haystack = `${body.HTML ?? ''}\n${body.Text ?? ''}`.replace(/&amp;/g, '&')
  const match = haystack.match(new RegExp(`${path.replace(/[/\\^$*+?.()|[\]{}]/g, '\\$&')}\\?token=[A-Za-z0-9_-]+`))
  if (match === null) throw new Error(`Message ${id} has no ${path} link: ${haystack.slice(0, 400)}`)
  return match[0]
}

/** Waits for the mail and returns its link as a path the SPA can open. */
export async function waitForMailLink(address: string, path: string): Promise<string> {
  let link = ''
  await expect
    .poll(
      async () => {
        const message = await latestMessageTo(address)
        if (message === null) return null
        link = await linkInMessage(message.ID, path)
        return link
      },
      { timeout: 30_000, intervals: [250, 500, 1_000, 2_000] },
    )
    .not.toBeNull()
  return link
}
