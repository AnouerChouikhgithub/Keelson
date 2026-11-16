import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

// Vitest runs without globals here, so Testing Library cannot register its own
// afterEach hook; each render would otherwise stay mounted for the next test.
afterEach(() => {
  cleanup()
})
