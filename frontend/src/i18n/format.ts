import type { Locale } from './index'

const currencyFormatters = new Map<Locale, Intl.NumberFormat>()
const numberFormatters = new Map<string, Intl.NumberFormat>()
const percentFormatters = new Map<Locale, Intl.NumberFormat>()
const dateFormatters = new Map<Locale, Intl.DateTimeFormat>()

export function formatCurrency(locale: Locale, value: number): string {
  let formatter = currencyFormatters.get(locale)
  if (!formatter) {
    formatter = new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: 'TND',
      minimumFractionDigits: 3,
      maximumFractionDigits: 3,
    })
    currencyFormatters.set(locale, formatter)
  }
  return formatter.format(value)
}

export function formatNumber(locale: Locale, value: number, maximumFractionDigits = 3): string {
  // Precision is part of the key: a locale-only key would return the formatter
  // built for the first precision and silently round later calls differently.
  const key = `${locale}:${maximumFractionDigits}`
  let formatter = numberFormatters.get(key)
  if (!formatter) {
    formatter = new Intl.NumberFormat(locale, { maximumFractionDigits })
    numberFormatters.set(key, formatter)
  }
  return formatter.format(value)
}


export function formatPercent(locale: Locale, ratio: number): string {
  let formatter = percentFormatters.get(locale)
  if (!formatter) {
    formatter = new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 1 })
    percentFormatters.set(locale, formatter)
  }
  return formatter.format(ratio)
}

export function formatDate(locale: Locale, iso: string): string {
  let formatter = dateFormatters.get(locale)
  if (!formatter) {
    formatter = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric' })
    dateFormatters.set(locale, formatter)
  }
  return formatter.format(new Date(iso))
}

export function formatMonth(locale: Locale, monthIndex: number): string {
  return new Intl.DateTimeFormat(locale, { month: 'short' }).format(new Date(2026, monthIndex, 1))
}

export function formatDateTime(locale: Locale, iso: string): string {
  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
}
