# UI specification (source of truth for all screens)

INSTRUCTIONS FOR THE CODING AGENT
- Implement the screens below as real, working code in `frontend/`. Wording such as "generate" or "visual reference" means: build them in the codebase.
- Work in the batches the developer asks for. Never skip, merge or summarize screens inside a requested batch.
- Every screen must support light AND dark mode through the central theme tokens. No hard-coded colors in components.
- Use typed mock data in `src/mocks/` for now. No backend calls until the developer says so.
- Reuse components. If a pattern appears twice, extract a component.
- Before the first batch, build the foundation: theme tokens, theme provider with Light / Dark / System, base components, navigation skeleton with a placeholder for every screen.
- Ask before adding a dependency that is not already listed in AGENTS.md. If a listed library is incompatible with the installed framework version, tell the developer and propose the closest alternative.

---

## Part 1: Shared design foundation

You are a senior product designer and front-end engineer. Design a professional, polished, production-grade UI. It must look like a funded startup's app, not a template.

THEMING (critical)
- Every screen must exist in BOTH light and dark mode. Default = follow system, plus a manual Light / Dark / System switch in Settings.
- Dark mode is NOT an inversion: use layered surfaces (background < surface < elevated), a slightly lighter and desaturated primary, subtle 1px borders instead of shadows, and softer white text (never pure #FFF on pure #000).
- All colors come from semantic tokens in ONE theme file (no hard-coded hex in components).
- Neutral tokens. Light: background #F8FAFC, surface #FFFFFF, border #E2E8F0, text #0F172A, text-secondary #475569. Dark: background #0B0F14, surface #121821, elevated #1A2230, border #263041, text #E6EAF0, text-secondary #9AA6B8.
- Status tokens. Light: success #16A34A, warning #D97706, danger #DC2626, info #2563EB. Dark: success #4ADE80, warning #FBBF24, danger #F87171, info #60A5FA.
- The project's primary color is given in the project prompt (light value and dark value). Use it sparingly: primary actions, active states, key highlights. Do not flood the UI with it.

DESIGN SYSTEM
- Font: Inter (system font fallback). Scale: display 28/34 semibold, title 20/28 semibold, body 16/24 regular, caption 13/18 medium. Tabular numbers for prices, times and metrics.
- 4pt spacing grid. Corner radius: 12 cards and inputs, 16 sheets and modals, full for chips and avatars.
- Minimum touch target 44pt (primary buttons 52pt tall). Respect safe areas.
- Icons: one consistent single-stroke set (Lucide), 1.75px stroke. No emoji as icons.
- Components to build once and reuse: buttons (primary, secondary, ghost, destructive, with loading state), text inputs (label above, helper text, error state, disabled), cards, list rows, chips and badges, avatars, segmented control, switch, bottom sheet or modal, toast, skeleton loaders.
- Every list and data screen needs designed states: loading (skeletons), empty (simple line icon + one-line explanation + primary CTA), error (message + Retry), and success feedback.
- Motion: 150-250ms ease-out transitions, subtle press feedback, haptics on key actions (mobile). Respect reduced-motion settings.
- Accessibility: WCAG AA contrast (4.5:1 text) in BOTH modes, visible focus states, labels for every control, dynamic text sizing must not break layouts.

CONTENT
- Use realistic sample data set in Tunisia: names like Amine Ben Salah, Ons Trabelsi, Yassine Jebali; cities Tunis, Sfax, Sousse, Bizerte, La Marsa; currency TND (e.g. 48,500 TND with 3 decimals where appropriate); French-style addresses. NO lorem ipsum, no gray placeholder boxes.
- Keep copy short, human, and action-oriented.

CODE RULES
- TypeScript, strict. Clean folder structure: theme/, components/, screens/, mocks/ (all sample data lives in mocks/). No backend calls yet: typed mock data only.
- Reusable components, no copy-pasted JSX. Keep the theme tokens centralized so the primary color can be changed in one place.

DO NOT: heavy gradients, drop shadows in dark mode, stock-photo clutter, more than one accent color, tiny text, crowded screens.

---

## Project spec

PROJECT: Keelson. A multi-tenant SaaS starter with a real demo product on top: invoicing for small businesses. Includes organizations, roles, invitations, Stripe-style subscriptions, usage limits and a platform admin panel. Feel: serious, trustworthy, finance-grade clarity, like Stripe or Linear.

PRIMARY COLOR: light #0F766E (white text on it), dark #2DD4BF (near-black text on it). Logo idea: a bold letter K built from a vertical beam and two angled strokes, like a ship's keel. Monochrome, sharp edges.

STACK: React, Vite, TypeScript, Tailwind CSS, shadcn/ui, lucide-react, Recharts. Responsive: desktop-first, fully usable on mobile web.

SCREENS (each in light AND dark, with table density, hover, loading skeleton and empty states):
1. Sign in / Sign up / Forgot password.
2. Create organization onboarding: org name, choose subdomain with live availability check ("acme.keelson.app"), invite teammates, pick a plan.
3. App dashboard: left sidebar (Dashboard, Invoices, Customers, Team, Billing, Settings) with org switcher at the top, KPI cards (Revenue this month in TND, Outstanding, Overdue, Paid rate) with small trend sparklines, revenue chart, recent invoices table, "Create invoice" primary button.
4. Invoices: data table with search, status filter tabs (All, Draft, Sent, Paid, Overdue), column sort, row actions menu, status badges, pagination, bulk select.
5. Invoice editor: two-pane layout. Left: form (customer, dates, line items with add / remove, tax, discount). Right: live PDF-style preview. Sticky bottom bar with Save draft and Send.
6. Customers: table + customer detail drawer.
7. Team and roles: members table with role dropdown (Owner, Admin, Member, Viewer), pending invitations with resend / revoke, invite dialog.
8. Billing: plan cards (Free, Pro, Business) with current plan highlighted, usage meters (invoices used, seats used), payment method card, invoice history table, upgrade modal.
9. Organization settings: name, subdomain, logo upload, danger zone (delete organization with typed confirmation), appearance toggle.
10. Platform admin (super-admin only): tenants table (plan, MRR, seats, status), MRR chart, impersonate and suspend actions.
11. Public pricing page: three-plan comparison with a monthly / yearly toggle.

DELIVERY: Generate ALL 11 screens listed above, each in light and dark mode, as ONE connected, navigable app (working sidebar and navigation between screens, working theme switch). Do not skip, merge or summarize any screen. These screens are the visual reference for the implementation, so be complete and consistent.
