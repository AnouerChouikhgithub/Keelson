import { describe, expect, it } from 'vitest'
import { ar } from './ar'
import { en } from './en'
import { fr } from './fr'

type Node = Record<string, unknown>

/**
 * Flattens a dictionary into `path -> kind` entries. Functions are compared by
 * kind only: their text is locale-specific, but every locale must expose the
 * same callable slots, otherwise a screen renders `undefined`.
 */
function shape(value: unknown, prefix = ''): Map<string, string> {
  const entries = new Map<string, string>()
  for (const [key, child] of Object.entries(value as Node)) {
    const path = prefix === '' ? key : `${prefix}.${key}`
    if (typeof child === 'function') entries.set(path, 'function')
    else if (Array.isArray(child)) entries.set(path, 'array')
    else if (typeof child === 'object' && child !== null) {
      entries.set(path, 'object')
      for (const [nested, kind] of shape(child, path)) entries.set(nested, kind)
    } else entries.set(path, typeof child)
  }
  return entries
}

/** Every string in the dictionary, keyed by path, including inside arrays. */
function strings(value: unknown, prefix = ''): Map<string, string> {
  const found = new Map<string, string>()
  for (const [key, child] of Object.entries(value as Node)) {
    const path = prefix === '' ? key : `${prefix}.${key}`
    if (typeof child === 'string') found.set(path, child)
    else if (Array.isArray(child)) {
      child.forEach((item, index) => {
        if (typeof item === 'string') found.set(`${path}[${index}]`, item)
      })
    } else if (typeof child === 'object' && child !== null) {
      for (const [nested, text] of strings(child, path)) found.set(nested, text)
    }
  }
  return found
}

/** Calls every message function so locale drift in its output is caught too. */
function functionOutputs(value: unknown, prefix = ''): Map<string, string> {
  const outputs = new Map<string, string>()
  for (const [key, child] of Object.entries(value as Node)) {
    const path = prefix === '' ? key : `${prefix}.${key}`
    if (typeof child === 'function') {
      const call = child as (...args: unknown[]) => unknown
      outputs.set(path, String(call('×', 2)))
    } else if (typeof child === 'object' && child !== null) {
      for (const [nested, text] of functionOutputs(child, path)) outputs.set(nested, text)
    }
  }
  return outputs
}

const englishShape = shape(en)

describe.each([
  ['fr', fr],
  ['ar', ar],
] as const)('%s dictionary', (locale, dictionary) => {
  it('has exactly the English keys and value kinds', () => {
    const localised = shape(dictionary)
    expect([...localised.keys()].sort()).toEqual([...englishShape.keys()].sort())
    for (const [path, kind] of englishShape) {
      expect(localised.get(path), `${locale}.${path}`).toBe(kind)
    }
  })

  it('has no empty strings', () => {
    const empty = [...strings(dictionary)]
      .filter(([, text]) => text.trim() === '')
      .map(([path]) => `${locale}.${path}`)
    expect(empty).toEqual([])
  })

  it('produces non-empty text from every message function', () => {
    const empty = [...functionOutputs(dictionary)]
      .filter(([, text]) => text.trim() === '' || text === 'undefined')
      .map(([path]) => `${locale}.${path}`)
    expect(empty).toEqual([])
  })
})

describe('English dictionary', () => {
  it('has no empty strings', () => {
    const empty = [...strings(en)]
      .filter(([, text]) => text.trim() === '')
      .map(([path]) => path)
    expect(empty).toEqual([])
  })
})
