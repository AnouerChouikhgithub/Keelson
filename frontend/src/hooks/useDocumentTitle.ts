import { useEffect } from 'react'

/** Keeps the browser tab title in step with the page's single `h1`. */
export function useDocumentTitle(title: string | null): void {
  useEffect(() => {
    if (title === null) return
    const previous = document.title
    document.title = `${title} · Keelson`
    return () => {
      document.title = previous
    }
  }, [title])
}
