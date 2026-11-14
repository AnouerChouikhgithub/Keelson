import { Icon } from './Icon'
import type { IconName } from './Icon'
import { useI18n } from '../i18n/I18nContext'
import { useTheme } from '../theme/ThemeContext'
import type { ThemeMode } from '../theme/ThemeContext'

const options: { value: ThemeMode; icon: IconName }[] = [
  { value: 'light', icon: 'sun' },
  { value: 'dark', icon: 'moon' },
  { value: 'system', icon: 'monitor' },
]

export function ThemeControl({ className }: { className?: string }) {
  const { m } = useI18n()
  const { mode, setMode } = useTheme()

  return (
    <div className={className ? `theme-control ${className}` : 'theme-control'} role="group" aria-label={m.theme.appearance}>
      {options.map((option) => (
        <button
          type="button"
          key={option.value}
          aria-label={m.theme.setTheme(m.theme[option.value])}
          aria-pressed={mode === option.value}
          className={mode === option.value ? 'theme-option selected' : 'theme-option'}
          onClick={() => setMode(option.value)}
        >
          <Icon name={option.icon} size={15} />
          <span>{m.theme[option.value]}</span>
        </button>
      ))}
    </div>
  )
}
