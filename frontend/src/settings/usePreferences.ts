import { useEffect, useState } from 'react'

export type PreferenceKey = 'invoiceUpdates' | 'paymentReminders' | 'teamActivity' | 'productNews'
export type Preferences = Record<PreferenceKey, boolean>

export const defaultPreferences: Preferences = {
  invoiceUpdates: true,
  paymentReminders: true,
  teamActivity: true,
  productNews: false,
}

const preferencesKey = 'keelson-notification-prefs'

function readStoredPreferences(): Preferences {
  if (typeof window === 'undefined') return defaultPreferences
  try {
    const parsed: unknown = JSON.parse(window.localStorage.getItem(preferencesKey) ?? 'null')
    if (!parsed || typeof parsed !== 'object') return defaultPreferences
    const candidate = parsed as Partial<Record<PreferenceKey, unknown>>
    // Only booleans are trusted; a corrupt or hand-edited value falls back to the default.
    return {
      invoiceUpdates: typeof candidate.invoiceUpdates === 'boolean' ? candidate.invoiceUpdates : defaultPreferences.invoiceUpdates,
      paymentReminders: typeof candidate.paymentReminders === 'boolean' ? candidate.paymentReminders : defaultPreferences.paymentReminders,
      teamActivity: typeof candidate.teamActivity === 'boolean' ? candidate.teamActivity : defaultPreferences.teamActivity,
      productNews: typeof candidate.productNews === 'boolean' ? candidate.productNews : defaultPreferences.productNews,
    }
  } catch {
    return defaultPreferences
  }
}

export function usePreferences() {
  const [preferences, setPreferences] = useState<Preferences>(readStoredPreferences)

  useEffect(() => {
    try {
      window.localStorage.setItem(preferencesKey, JSON.stringify(preferences))
    } catch {
      // Preferences still apply for this session when storage is unavailable.
    }
  }, [preferences])

  function toggle(key: PreferenceKey) {
    setPreferences((current) => ({ ...current, [key]: !current[key] }))
  }

  return { preferences, toggle }
}
