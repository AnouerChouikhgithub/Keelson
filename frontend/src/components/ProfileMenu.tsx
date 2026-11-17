import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { Icon } from './Icon'
import type { IconName } from './Icon'
import { useI18n } from '../i18n/I18nContext'

type MenuLink = { kind: 'link'; key: string; label: string; icon: IconName; to: string }

/**
 * Account menu: real links, so the browser's own affordances work (open in a
 * new tab, copy the address) and the route is the single source of truth.
 */
export function ProfileMenu({
  orgId,
  onLogout,
  logoutPending,
  onNavigate,
}: {
  orgId: string
  onLogout: () => void
  logoutPending: boolean
  /** Lets the shell close its mobile drawer after a menu link was followed. */
  onNavigate?: () => void
}) {
  const { m } = useI18n()
  const [open, setOpen] = useState(false)
  const wrapRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const firstItemRef = useRef<HTMLAnchorElement>(null)

  const entries: MenuLink[] = [
    { kind: 'link', key: 'account', label: m.profile.myAccount, icon: 'users', to: `/o/${orgId}/account` },
    { kind: 'link', key: 'notifications', label: m.profile.notifications, icon: 'bell', to: `/o/${orgId}/notifications` },
    { kind: 'link', key: 'settings', label: m.profile.settings, icon: 'settings', to: `/o/${orgId}/settings` },
  ]

  useEffect(() => {
    if (!open) return
    firstItemRef.current?.focus()

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
        <div className="profile-menu" role="menu" aria-label={m.nav.profileMenuLabel}>
          {entries.map((entry, index) => (
            <Link
              key={entry.key}
              ref={index === 0 ? firstItemRef : undefined}
              role="menuitem"
              className="profile-menu-item"
              to={entry.to}
              onClick={() => {
                setOpen(false)
                onNavigate?.()
              }}
            >
              <Icon name={entry.icon} size={16} />
              <span>{entry.label}</span>
            </Link>
          ))}
          <button
            type="button"
            role="menuitem"
            className="profile-menu-item profile-menu-logout"
            aria-busy={logoutPending || undefined}
            disabled={logoutPending}
            onClick={() => {
              setOpen(false)
              onLogout()
            }}
          >
            <Icon name="logout" size={16} />
            <span>{logoutPending ? m.session.signingOut : m.profile.logout}</span>
          </button>
        </div>
      )}
    </div>
  )
}
