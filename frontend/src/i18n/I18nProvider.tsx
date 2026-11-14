import { useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { I18nContext } from './I18nContext'
import type { I18nValue } from './I18nContext'
import { formatCurrency, formatDate, formatDateTime, formatMonth, formatNumber, formatPercent } from './format'
import { locales, messages } from './messages'
import type { Locale } from './messages'

const localeKey = 'keelson-locale'

function readStoredLocale(): Locale {
  if (typeof window === 'undefined') return 'en'
  try {
    const stored = window.localStorage.getItem(localeKey)
    return locales.includes(stored as Locale) ? (stored as Locale) : 'en'
  } catch {
    return 'en'
  }
}

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>(readStoredLocale)
  const dir = locale === 'ar' ? 'rtl' : 'ltr'

  useEffect(() => {
    document.documentElement.lang = locale
    document.documentElement.dir = dir
    try {
      window.localStorage.setItem(localeKey, locale)
    } catch {
      // The choice still applies for this session when storage is unavailable.
    }
  }, [locale, dir])

  const value = useMemo<I18nValue>(() => ({
    locale,
    setLocale: setLocaleState,
    m: messages[locale],
    dir,
    currency: (amount) => formatCurrency(locale, amount),
    number: (amount, maximumFractionDigits) => formatNumber(locale, amount, maximumFractionDigits),
    percent: (ratio) => formatPercent(locale, ratio),
    date: (iso) => formatDate(locale, iso),
    dateTime: (iso) => formatDateTime(locale, iso),
    month: (monthIndex) => formatMonth(locale, monthIndex),
  }), [locale, dir])

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>
}
