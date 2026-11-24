/**
 * The two Node builtins a test uses to read a backend source file.
 *
 * `tsconfig.app.json` deliberately types `src` for the browser only, so `node:fs`
 * is not declared there and a test may not assume the Node globals exist. This
 * narrow declaration gives the one test that reads
 * `backend/src/Audit/InvoiceAuditAction.php` exactly the two functions it calls —
 * instead of adding `"node"` to the application's types, which would let browser
 * code reference `process` and `Buffer` and still typecheck.
 */
declare module 'node:fs' {
  export function readFileSync(path: string, encoding: 'utf8'): string
}

declare module 'node:url' {
  export function fileURLToPath(url: URL | string): string
}
