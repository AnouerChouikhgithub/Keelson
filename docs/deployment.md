# Deployment

The backend is a JSON API; the frontend is a static Vite build. Both sit behind
one TLS-terminating reverse proxy.

## API responses

The application sets these itself (`App\EventListener\SecurityHeadersListener`)
on every `/api` response, so a proxy must not duplicate or override them:

| Header | Value |
| --- | --- |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (production, HTTPS only) |
| `Cache-Control` | `no-store, private` (authenticated responses) |

## Static frontend

The SPA is served from the same site but a different path (or host). Because it
loads scripts and styles, its policy is necessarily looser than the API's, and a
reverse proxy has to add it — the build cannot.

```nginx
location / {
    root /srv/keelson/frontend/dist;
    try_files $uri $uri/ /index.html;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' https://api.example.com; frame-ancestors 'none'; base-uri 'none'; form-action 'self'" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
}

location /api/ {
    proxy_pass http://127.0.0.1:8000;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    client_max_body_size 1m;   # the API refuses larger bodies anyway
}
```

Notes:

- `unsafe-inline` for styles is required by the current build's inlined critical
  CSS; remove it once the build emits only external stylesheets.
- Keep `X-Forwarded-Proto` and configure `TRUSTED_PROXIES` +
  `TRUSTED_HEADERS` in the backend, otherwise the session cookie is not marked
  `Secure` and HSTS is not emitted.
- The API caps a body at 1 MiB (`415`/`413` guards). The proxy's
  `client_max_body_size` is the streaming boundary; the application check is the
  correctness boundary.
- Never cache `/api/` responses in the proxy.
