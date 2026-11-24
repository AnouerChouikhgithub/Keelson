#!/usr/bin/env node
/**
 * Fails when a stylesheet outside the theme file hard-codes a colour.
 *
 * `src/theme/tokens.css` is the one place colour values live: it holds the brand
 * colour, both themes and the print tokens. A literal `#2A0A0A` inside a component
 * is invisible to the theme — it does not change with `data-theme`, and it drifts
 * from the palette the rest of the app uses (the danger button's dark-mode text
 * was exactly that). Components read `var(--color-…)` instead.
 *
 *   node scripts/no-raw-colors.mjs        # scans src/
 *
 * Exit status: 0 clean, 1 a raw colour was found.
 */
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const src = join(here, '..', 'src')
const themeFile = join(src, 'theme', 'tokens.css')

/** Hex, rgb()/rgba() and hsl()/hsla(): the three ways a literal colour is written. */
const RAW_COLOR = /#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/g

/** @param {string} directory @returns {string[]} */
function stylesheets(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) return stylesheets(path)
    return entry.isFile() && entry.name.endsWith('.css') ? [path] : []
  })
}

const findings = []
for (const file of stylesheets(src)) {
  if (file === themeFile) continue
  const lines = readFileSync(file, 'utf8').split(/\r?\n/)
  lines.forEach((line, index) => {
    for (const match of line.matchAll(RAW_COLOR)) {
      findings.push(`${relative(join(here, '..'), file)}:${index + 1}  ${match[0]}  ${line.trim()}`)
    }
  })
}

if (findings.length > 0) {
  console.error('Raw colours outside src/theme/tokens.css:')
  for (const finding of findings) console.error(`  ${finding}`)
  console.error('\nAdd a token to src/theme/tokens.css (both themes) and use var(--…).')
  process.exit(1)
}

console.log('No raw colours outside src/theme/tokens.css.')
