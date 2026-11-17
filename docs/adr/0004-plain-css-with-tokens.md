# ADR 0004 — Plain CSS with design tokens, no CSS framework

- **Status:** accepted
- **Date:** 2026-11-16

## Context

The SPA needs a small, coherent design system: one brand colour, light and dark
themes, logical properties for right-to-left Arabic, and a handful of components
(panel, table, field, dialog, toast). The realistic choices were a utility
framework (Tailwind), a component library (MUI, Chakra), or hand-written CSS with
custom properties.

A framework brings a build step, a second configuration language and a large
dependency for a surface this size; a component library brings its own opinions
about markup, which conflicts with the accessibility work the screens already do
(focus traps, live regions, labelled tables).

## Decision

Write plain CSS, one stylesheet per component or screen, with every colour,
radius, shadow and spacing scale declared as a custom property in
`frontend/src/theme/tokens.css`. `--color-primary: #0F766E` is the single brand
colour. Layout uses logical properties (`padding-inline`, `inset-inline-start`)
so `dir="rtl"` mirrors without per-locale rules.

## Consequences

- No CSS dependency, no utility-class churn in the markup, and the theme is one
  file a designer can read.
- Screen-level stylesheets can drift; the mitigation is that every colour must be
  a token, so a stray hex value is visible in review.
- Reusable primitives are React components (`Field`, `DataTable`, `OverlaySurface`)
  rather than framework components, which keeps the markup semantic and lets the
  components own their ARIA wiring.
- Dark mode is a token override on `[data-theme="dark"]`, not a second stylesheet.
- Contributors have to know CSS; there is no utility vocabulary to learn instead.
