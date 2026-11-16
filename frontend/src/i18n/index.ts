import { ar } from './ar'
import { en } from './en'
import { fr } from './fr'

/**
 * English defines the message shape (`Messages = typeof en`) and `fr`/`ar` are
 * checked against it, so a missing key is a type error. Copy lives in
 * `src/i18n/<locale>/<area>.ts`; screen code only ever reads `m.<area>.<key>`.
 */
export const messages = { en, fr, ar }

export type Locale = keyof typeof messages
export type Messages = typeof en

export const locales: Locale[] = ['en', 'fr', 'ar']
