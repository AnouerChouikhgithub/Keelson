/** Two-letter avatar initials derived from a display name; never fabricated. */
export function initialsOf(name: string): string {
  // Parenthesised or punctuated tokens ("Dune Textiles (fixture)") would
  // otherwise contribute a bracket as an initial.
  const parts = name.trim().split(/\s+/).filter((part) => /^[\p{L}\p{N}]/u.test(part))
  if (parts.length === 0) return '?'
  const letters = parts.length === 1
    ? parts[0].slice(0, 2)
    : `${parts[0][0]}${parts[parts.length - 1][0]}`
  return letters.toLocaleUpperCase()
}
