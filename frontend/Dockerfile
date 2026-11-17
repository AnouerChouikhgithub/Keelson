# syntax=docker/dockerfile:1

# Production image for the SPA: pnpm builds the bundle in a Node stage, and the
# result is served by nginx. Nothing from the build toolchain ships.
#
#   docker build -t keelson-web .
#   docker run --rm -p 8080:80 keelson-web

FROM node:24-alpine AS build

RUN corepack enable
WORKDIR /app

# Dependencies first, so editing a screen does not reinstall them.
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

COPY . .

# The SPA talks to its own origin by default (the nginx server block forwards
# /api), so the base URL is only set when the API lives elsewhere.
ARG VITE_API_BASE_URL=
ENV VITE_API_BASE_URL=$VITE_API_BASE_URL
RUN pnpm run build


FROM nginx:1.29-alpine AS runtime

RUN rm -f /etc/nginx/conf.d/default.conf
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

# Runs as the image's own non-root user where nginx allows it: nginx needs to
# write its pid and temp files, so the packaged entrypoint stays in charge and the
# containers run read-only except for those paths (see the compose example).
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1/healthz >/dev/null || exit 1
