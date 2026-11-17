import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

/**
 * Reports dictionary keys that no screen references.
 *
 * The dictionaries are plain object literals (`export const customers = { … }`)
 * and screens read them through `m`, so a key counts as used when some source
 * file contains `m.<namespace>.<path>`, when it sits under a namespace that is
 * passed around whole (`const labels = m.roles`), or when a parent path is used
 * (`m.roles.admin` keeps `roles.admin` used).
 *
 *   node scripts/unused-i18n.mjs        # report; exit 1 when something is unused
 *   node scripts/unused-i18n.mjs --fix  # delete the unused one-line keys in en, fr, ar
 *
 * Keys built at runtime cannot be seen by a text scan, so a small allowlist keeps
 * them. Every entry states why.
 */
const ALLOW = new Map([
  ['status.draft', 'labels come from an API value: m.status[status]'],
  ['status.sent', 'labels come from an API value: m.status[status]'],
  ['status.paid', 'labels come from an API value: m.status[status]'],
  ['status.overdue', 'labels come from an API value: m.status[status]'],
  ['theme.light', 'chosen from the stored theme: m.theme[key]'],
  ['theme.dark', 'chosen from the stored theme: m.theme[key]'],
  ['theme.system', 'chosen from the stored theme: m.theme[key]'],
  ['search.kinds.page', 'selected by a result kind: m.search.kinds[kind]'],
  ['search.kinds.organization', 'selected by a result kind: m.search.kinds[kind]'],
  ['search.kinds.customer', 'selected by a result kind: m.search.kinds[kind]'],
  ['search.kinds.invoice', 'selected by a result kind: m.search.kinds[kind]'],
  ['invoices.filters.all', 'selected by a filter value: m.invoices.filters[value]'],
  ['invoices.filters.draft', 'selected by a filter value: m.invoices.filters[value]'],
  ['invoices.filters.sent', 'selected by a filter value: m.invoices.filters[value]'],
  ['invoices.filters.paid', 'selected by a filter value: m.invoices.filters[value]'],
  ['invoices.filters.overdue', 'selected by a filter value: m.invoices.filters[value]'],
  // The feed looks the label up by the server's notification type.
  ['notifications.types.invoice_overdue', 'looked up by type: dictionary[notification.type]'],
  ['notifications.types.invoice_paid', 'looked up by type: dictionary[notification.type]'],
  ['notifications.types.invitation_accepted', 'looked up by type: dictionary[notification.type]'],
  ['notifications.types.member_joined', 'looked up by type: dictionary[notification.type]'],
])

const ROOT = process.cwd()
const DICTS = ['src/i18n/en', 'src/i18n/fr', 'src/i18n/ar']
const SOURCE = join(ROOT, 'src')

function walk(dir) {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name)
    return entry.isDirectory() ? walk(path) : /\.(ts|tsx)$/.test(entry.name) ? [path] : []
  })
}

/**
 * Entries of one dictionary file: `{ namespace, path, index, oneLiner }`.
 * Keys are read from the text, tracking the object nesting by key indentation.
 */
function entries(file) {
  const lines = readFileSync(file, 'utf8').split(/\r?\n/)
  const anchors = []
  const found = []
  let namespace = null
  for (const [index, line] of lines.entries()) {
    const declaration = /^export const (\w+) = \{/.exec(line)
    if (declaration) {
      namespace = declaration[1]
      anchors.length = 0
      continue
    }
    if (namespace === null) continue
    if (/^\}/.test(line)) {
      namespace = null
      continue
    }
    const key = /^(\s+)(\w+):(.*)$/.exec(line)
    if (key === null) continue
    const indent = key[1].length
    while (anchors.length > 0 && anchors[anchors.length - 1].indent >= indent) anchors.pop()
    const path = [...anchors.map((anchor) => anchor.name), key[2]].join('.')
    const value = key[3].trim()
    if (value.startsWith('{')) {
      anchors.push({ name: key[2], indent })
    } else {
      found.push({ namespace, path, index, oneLiner: value.length > 0 && !value.startsWith('/*') })
    }
  }
  return found
}

function usedPaths() {
  const used = new Set()
  const whole = new Set()
  for (const file of walk(SOURCE)) {
    if (file.includes(join('src', 'i18n'))) continue
    const source = readFileSync(file, 'utf8')
    for (const match of source.matchAll(/\bm\.(\w+)((?:\.\w+)*)/g)) {
      if (match[2] === '') whole.add(match[1])
      else used.add(`${match[1]}${match[2]}`)
    }
    // `messages[locale].common.x` reads one key from every dictionary.
    for (const match of source.matchAll(/\bmessages\[[^\]]*\]\.(\w+(?:\.\w+)*)/g)) {
      used.add(match[1])
    }
    // `const { title, body } = m.customers` uses those keys of that namespace.
    for (const match of source.matchAll(/\{([^}]*)\}\s*=\s*m\.(\w+)/g)) {
      for (const name of match[1].split(',')) {
        const trimmed = name.trim().split(/[:=\s]/)[0]
        if (/^\w+$/.test(trimmed)) used.add(`${match[2]}.${trimmed}`)
      }
    }
  }
  return { used, whole }
}

const { used, whole } = usedPaths()
const unused = []
for (const dir of DICTS) {
  for (const name of readdirSync(join(ROOT, dir)).filter((file) => file.endsWith('.ts') && file !== 'index.ts')) {
    for (const entry of entries(join(ROOT, dir, name))) {
      const full = `${entry.namespace}.${entry.path}`
      if (entry.namespace === undefined) continue
      if (whole.has(entry.namespace) || used.has(full)) continue
      if ([...used].some((candidate) => candidate.startsWith(`${full}.`))) continue
      if (ALLOW.has(full)) continue
      unused.push({ ...entry, dir, name, full })
    }
  }
}

const unique = [...new Map(unused.map((entry) => [entry.full, entry])).values()]
if (unique.length === 0) {
  console.log('No unused dictionary keys.')
  process.exit(0)
}

console.log(`Unused dictionary keys (${unique.length}):`)
for (const entry of unique) console.log(`  ${entry.full}`)

if (!process.argv.includes('--fix')) {
  console.log('\nRun with --fix to delete them from en, fr and ar.')
  process.exit(1)
}

let deleted = 0
for (const dir of DICTS) {
  for (const name of readdirSync(join(ROOT, dir)).filter((file) => file.endsWith('.ts') && file !== 'index.ts')) {
    const file = join(ROOT, dir, name)
    const doomed = new Set(
      entries(file)
        .filter((entry) => entry.oneLiner && unique.some((candidate) => candidate.full === `${entry.namespace}.${entry.path}`))
        .map((entry) => entry.index),
    )
    if (doomed.size === 0) continue
    const kept = readFileSync(file, 'utf8').split(/\r?\n/).filter((_, index) => !doomed.has(index))
    writeFileSync(file, kept.join('\n'))
    deleted += doomed.size
  }
}

console.log(`\nDeleted ${deleted} key lines.`)
console.log('Run `pnpm exec tsc -b` and `pnpm test`: the parity test fails if en, fr and ar drift.')
