import { useEffect, useMemo, useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'
import { Icon } from './Icon'
import type { IconName } from './Icon'
import { useI18n } from '../i18n/I18nContext'
import { filterSearchResults, searchShortcutLabel } from '../search/search'
import type { SearchResult, SearchResultKind } from '../search/search'
import type { PageId } from '../pages'

const kindIcons: Record<SearchResultKind, IconName> = {
  page: 'grid',
  organization: 'building',
  invoice: 'file',
  customer: 'users',
  notification: 'bell',
}

type SearchDialogProps = {
  results: SearchResult[]
  onClose: () => void
  onNavigate: (page: PageId) => void
}

/** Mounted only while open, so closing and reopening always starts with fresh state. */
export function SearchDialog({ results, onClose, onNavigate }: SearchDialogProps) {
  const { m, locale } = useI18n()
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(0)
  const inputRef = useRef<HTMLInputElement>(null)
  const dialogRef = useRef<HTMLDivElement>(null)
  const filtered = useMemo(() => filterSearchResults(results, query, locale), [results, query, locale])
  const shortcut = searchShortcutLabel()

  useEffect(() => {
    const returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    inputRef.current?.focus()

    return () => {
      document.body.style.overflow = previousOverflow
      returnFocus?.focus()
    }
  }, [])

  function choose(result: SearchResult) {
    onClose()
    onNavigate(result.page)
  }

  function handleKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key === 'Escape') {
      event.preventDefault()
      onClose()
      return
    }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      if (filtered.length === 0) return
      const delta = event.key === 'ArrowDown' ? 1 : -1
      setActiveIndex((current) => (current + delta + filtered.length) % filtered.length)
      return
    }
    if (event.key === 'Enter' && filtered[activeIndex]) {
      event.preventDefault()
      choose(filtered[activeIndex])
      return
    }
    if (event.key === 'Tab') {
      const focusable = dialogRef.current?.querySelectorAll<HTMLElement>('input, button')
      if (!focusable || focusable.length === 0) return
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }
  }

  return (
    <div
      className="search-overlay"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        className="search-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="search-dialog-title"
        ref={dialogRef}
        onKeyDown={handleKeyDown}
      >
        <h2 id="search-dialog-title" className="visually-hidden">{m.search.title}</h2>
        <div className="search-field">
          <Icon name="search" size={18} />
          <input
            ref={inputRef}
            type="search"
            value={query}
            onChange={(event) => {
              setQuery(event.target.value)
              setActiveIndex(0)
            }}
            placeholder={m.search.placeholder}
            aria-label={m.search.title}
            aria-controls={filtered.length > 0 ? 'search-results' : undefined}
            aria-activedescendant={filtered[activeIndex] ? `search-option-${activeIndex}` : undefined}
            autoComplete="off"
            spellCheck={false}
          />
          <kbd className="search-shortcut" title={m.search.shortcutHint(shortcut)}>{shortcut}</kbd>
          <button type="button" className="search-close icon-button" aria-label={m.search.close} onClick={onClose}>
            <Icon name="close" size={16} />
          </button>
        </div>

        <p className="visually-hidden" role="status" aria-live="polite">
          {filtered.length > 0 ? m.search.resultCount(filtered.length) : ''}
        </p>

        {query.trim() === '' ? (
          <div className="search-empty">
            <span className="search-empty-icon"><Icon name="search" size={20} /></span>
            <h3>{m.search.emptyTitle}</h3>
            <p>{m.search.emptyBody}</p>
            <span className="search-hint"><Icon name="sparkle" size={14} /> {m.search.shortcutHint(shortcut)}</span>
          </div>
        ) : filtered.length === 0 ? (
          <div className="search-empty">
            <span className="search-empty-icon"><Icon name="search" size={20} /></span>
            <h3>{m.search.noResults(query.trim())}</h3>
            <p>{m.search.noResultsHint}</p>
          </div>
        ) : (
          <ul className="search-results" id="search-results" role="listbox" aria-label={m.search.title}>
            {filtered.map((result, index) => (
              <li
                key={result.id}
                id={`search-option-${index}`}
                role="option"
                aria-selected={index === activeIndex}
                className={`search-result ${index === activeIndex ? 'active' : ''}`}
                onMouseEnter={() => setActiveIndex(index)}
                onClick={() => choose(result)}
              >
                <span className="search-result-icon"><Icon name={kindIcons[result.kind]} size={16} /></span>
                <span className="search-result-copy">
                  <strong>{result.title}</strong>
                  {result.subtitle && <small>{result.subtitle}</small>}
                </span>
                <span className="search-result-kind">{m.search.kinds[result.kind]}</span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  )
}
