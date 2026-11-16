import { Component } from 'react'
import type { ErrorInfo, ReactNode } from 'react'
import { useI18n } from '../i18n/I18nContext'
import { ErrorState } from './ErrorState'

type BoundaryProps = {
  title: string
  body: string
  reloadLabel: string
  children: ReactNode
}

type BoundaryState = { failed: boolean }

/**
 * Catches a render failure inside one route so the shell (navigation, theme,
 * sign-out) keeps working. Recovery resets the boundary; the route is rendered
 * again from the server's data rather than from stale local state.
 */
class Boundary extends Component<BoundaryProps, BoundaryState> {
  state: BoundaryState = { failed: false }

  static getDerivedStateFromError(): BoundaryState {
    return { failed: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // The console is the only sink: no stack trace is sent anywhere.
    console.error('Route render failed', error, info.componentStack)
  }

  render() {
    if (this.state.failed) {
      const { title, body, reloadLabel } = this.props
      return (
        <ErrorState
          title={title}
          message={body}
          retryLabel={reloadLabel}
          onRetry={() => this.setState({ failed: false })}
        />
      )
    }
    return this.props.children
  }
}

export function ErrorBoundary({ children }: { children: ReactNode }) {
  const { m } = useI18n()
  return (
    <Boundary title={m.errors.boundaryTitle} body={m.errors.boundaryBody} reloadLabel={m.errors.boundaryReload}>
      {children}
    </Boundary>
  )
}
