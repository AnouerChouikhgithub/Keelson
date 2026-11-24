import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

/**
 * Two projects, chosen by file extension.
 *
 * `*.test.ts` exercises the API/session layer and needs no DOM, so it keeps the
 * fast node environment. `*.test.tsx` renders components and needs jsdom plus
 * Testing Library's cleanup, which only that project pays for.
 */
export default defineConfig({
  test: {
    projects: [
      {
        test: {
          name: 'node',
          environment: 'node',
          include: ['src/**/*.test.ts'],
        },
      },
      {
        plugins: [react()],
        test: {
          name: 'jsdom',
          environment: 'jsdom',
          include: ['src/**/*.test.tsx'],
          setupFiles: ['./src/test/setup.ts'],
          // Stylesheets must reach jsdom, or a screen that renders a class no
          // imported sheet defines cannot be told apart from a styled one.
          css: true,
          // Every keystroke is a real event, and a jsdom file pays its own
          // environment boot; the default 5 s is tight when the pool runs a
          // worker per file on a busy machine.
          testTimeout: 15_000,
        },
      },
    ],
  },
})
