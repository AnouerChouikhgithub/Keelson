import { useEffect, useRef, useState } from 'react'
import { Icon } from './Icon'
import type { IconName } from './Icon'
import { useI18n } from '../i18n/I18nContext'
import type { PageId } from '../pages'

type MenuItem =
  | { kind: 'page'; key: string; label: string; icon: IconName; page: PageId }
  | { kind: 'logout'; key: string; label: string; icon: IconName }

export function ProfileMenu({
  onNavigate,
  onLogout,
  logoutPending,
}: {
  onNavigate: (page: PageId) => void
  onLogout: () => void
  logoutPending: boolean
}) {
  const { m } = useI18n()
  const [open, setOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(0)
  const wrapRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const itemRefs = useRef<(HTMLButtonElement | null)[]>([])

  const entries: MenuItem[] = [
    { kind: 'page', key: 'account', label: m.profile.myAccount, icon: 'users', page: 'account' },
    { kind: 'page', key: 'notifications', label: m.profile.notifications, icon: 'bell', page: 'notifications' },
    { kind: 'page', key: 'settings', label: m.profile.settings, icon: 'settings', page: 'settings' },
    { kind: 'logout', key: 'logout', label: m.profile.logout, icon: 'logout' },
  ]

  useEffect(() => {
    if (!open) return
    itemRefs.current[0]?.focus()

    function onPointerDown(event: PointerEvent) {
      if (!wrapRef.current?.contains(event.target as Node)) setOpen(false)
    }
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false)
        triggerRef.current?.focus()
      }
    }
    document.addEventListener('pointerdown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  function focusItem(index: number) {
    const next = (index + entries.length) % entries.length
    setActiveIndex(next)
    itemRefs.current[next]?.focus()
  }

  function activate(item: MenuItem) {
    setOpen(false)
    if (item.kind === 'page') {
      onNavigate(item.page)
      triggerRef.current?.focus()
      return
    }
    // Sign-out is not a navigation: the server decides the outcome, so the
    // menu closes and the caller reports the real result.
    onLogout()
  }

  return (
    <div className="profile-menu-wrap" ref={wrapRef}>
      <button
        ref={triggerRef}
        type="button"
        aria-label={m.nav.openProfileMenu}
        aria-haspopup="menu"
        aria-expanded={open}
        className="icon-button"
        onClick={() => setOpen((current) => !current)}
      >
        <Icon name="more" />
      </button>
      {open && (
        <div
          className="profile-menu"
          role="menu"
          aria-label={m.nav.profileMenuLabel}
          onKeyDown={(event) => {
            if (event.key === 'ArrowDown') {
              event.preventDefault()
              focusItem(activeIndex + 1)
            } else if (event.key === 'ArrowUp') {
              event.preventDefault()
              focusItem(activeIndex - 1)
            } else if (event.key === 'Home') {
              event.preventDefault()
              focusItem(0)
            } else if (event.key === 'End') {
              event.preventDefault()
              focusItem(entries.length - 1)
            } else if (event.key === 'Tab') {
              setOpen(false)
            }
          }}
        >
          {entries.map((entry, index) => (
            <button
              key={entry.key}
              ref={(element) => { itemRefs.current[index] = element }}
              type="button"
              role="menuitem"
              className={`profile-menu-item ${entry.kind === 'logout' ? 'profile-menu-logout' : ''}`}
              aria-busy={entry.kind === 'logout' && logoutPending ? true : undefined}
              disabled={entry.kind === 'logout' && logoutPending}
              tabIndex={-1}
              onClick={() => activate(entry)}
            >
              <Icon name={entry.icon} size={16} />
              <span>{entry.kind === 'logout' && logoutPending ? m.session.signingOut : entry.label}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
