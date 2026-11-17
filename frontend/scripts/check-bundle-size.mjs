import { readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

/**
 * Fails the build when the shipped JavaScript grows past its budget.
 *
 * The budget is not a target, it is a ceiling: it exists so a new dependency or a
 * screen that pulls a library into the entry chunk is a deliberate decision in
 * review rather than a surprise in production. Raise it in this file, in the same
 * commit, when the growth is intended.
 */
const DIST = 'dist/assets'
const BUDGET = {
  /** The entry chunk every visitor downloads first. */
  entry: 420 * 1024,
  /** Every JavaScript file the build emits, uncompressed. */
  total: 800 * 1024,
}

let files
try {
  files = readdirSync(DIST).filter((name) => name.endsWith('.js'))
} catch {
  console.error(`${DIST} not found. Run \`pnpm run build\` first.`)
  process.exit(2)
}

if (files.length === 0) {
  console.error(`${DIST} contains no JavaScript. Did the build run?`)
  process.exit(2)
}

const sizes = files.map((name) => ({ name, bytes: statSync(join(DIST, name)).size }))
const entry = sizes.find((file) => /^index-[\w-]+\.js$/.test(file.name))
const total = sizes.reduce((sum, file) => sum + file.bytes, 0)
const kb = (bytes) => `${(bytes / 1024).toFixed(1)} kB`

const failures = []
if (entry === undefined) {
  // A renamed entry chunk would silently skip the check, so its absence fails.
  failures.push(`no entry chunk matching index-*.js in ${DIST}`)
} else if (entry.bytes > BUDGET.entry) {
  failures.push(`entry ${entry.name} is ${kb(entry.bytes)}, over the ${kb(BUDGET.entry)} budget`)
}
if (total > BUDGET.total) {
  failures.push(`all JavaScript is ${kb(total)}, over the ${kb(BUDGET.total)} budget`)
}

for (const file of [...sizes].sort((a, b) => b.bytes - a.bytes).slice(0, 5)) {
  console.log(`  ${kb(file.bytes).padStart(10)}  ${file.name}`)
}
console.log(`\nentry: ${entry === undefined ? 'missing' : kb(entry.bytes)} (budget ${kb(BUDGET.entry)})`)
console.log(`total: ${kb(total)} (budget ${kb(BUDGET.total)})`)

if (failures.length > 0) {
  console.error(`\nBundle budget exceeded:\n  - ${failures.join('\n  - ')}`)
  process.exit(1)
}

console.log('\nBundle is within budget.')
