import { createContext, useContext } from 'react'
import type { Locale, Messages } from './messages'

export type I18nValue = {
  locale: Locale
  setLocale: (locale: Locale) => void
  m: Messages
  dir: 'ltr' | 'rtl'
  currency: (value: number) => string
  number: (value: number, maximumFractionDigits?: number) => string
  percent: (ratio: number) => string
  date: (iso: string) => string
  dateTime: (iso: string) => string
  month: (monthIndex: number) => string
}

export const I18nContext = createContext<I18nValue | null>(null)

export function useI18n(): I18nValue {
  const value = useContext(I18nContext)
  if (!value) throw new Error('useI18n must be used inside I18nProvider')
  return value
}
