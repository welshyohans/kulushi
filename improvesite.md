# Improve Site Plan (MerKato Pro)

## Goals
- Modern, attractive UI (mobile-first, touch-friendly).
- Fast loading (minimize blocking resources, cache shared assets, lazy-load images).
- Easy to use (clear navigation, consistent spacing, readable typography, good empty/loading states).
- Portable embedding (API calls use a full/absolute base URL so the UI can run from other domains/paths).

## High-Level Approach
1. Create a tiny shared runtime config for **API base URL** and URL-building utilities.
2. Unify design tokens (colors, radii, typography) + consistent headers/footers across pages.
3. Refine UX flows: login → dashboard → order history (and back), with sensible redirects.
4. Apply performance wins: `preconnect`, `defer`, caching via service worker, image lazy-loading.

## API Base URL (for “use this website in other sites”)
### Requirements
- All `fetch()` calls must use **absolute URLs** (e.g. `https://your-domain.com/api/...`), not relative `api/...`.
- The API base must be configurable without editing application logic.

### Implementation Plan
- Add a shared script: `assets/js/mpro-runtime.js`
  - Resolves API base URL using:
    1) `<meta name="mpro-api-base" content="https://your-domain.com/">` (preferred for embedding)
    2) `localStorage.mproApiBaseUrl` (optional override)
    3) fallback: `window.location.origin + '/'`
  - Exposes helpers:
    - `window.MPRO.apiUrl(path)`
    - `window.MPRO.buildApiUrl(path, params)`
- Add `<meta name="mpro-api-base" content="">` to `index.html`, `login.html`, `orderHistory.html`.
- Replace all relative API paths with `window.MPRO.apiUrl(...)` / `window.MPRO.buildApiUrl(...)`.

### How an integrator uses it
- Set API base in HTML:
  - `<meta name="mpro-api-base" content="https://example.com/">`
- Or set via console once:
  - `localStorage.setItem('mproApiBaseUrl','https://example.com/')`

## Page Improvements
### `index.html` (Dashboard)
- Mobile-first layout polish: spacing scale, consistent cards, clearer CTAs.
- Improve perceived speed: skeleton loaders for goods/categories/suppliers.
- Performance:
  - Add `preconnect` for CDN assets.
  - `defer` Bootstrap JS.
  - Ensure images use `loading="lazy"` and/or existing IntersectionObserver.
- Ensure all API calls use absolute URL helper.

### `login.html`
- Make sign-in clearer + faster:
  - Better input labels/hints, loading states, error states, and password toggle UX.
  - Keep the existing Android app promotion, but ensure it doesn’t block login.
- Ensure all API calls use absolute URL helper.

### `orderHistory.html`
- Mobile-first readability:
  - Orders list and order details remain usable on small screens (cards, responsive columns).
  - Clear empty, error, and loading states.
- Ensure all API calls use absolute URL helper.

## Performance & PWA
- Update `service-worker.js` cache list to include new shared JS and bump cache version.
- Keep dependencies light; avoid adding new heavy UI frameworks.
- Add `color-scheme` + safe viewport meta for better OS integration.

## QA Checklist
- Mobile (Android Chrome): login, load dashboard, search goods, open cart, place order, open order history.
- Embed scenario: open the pages from a different host/path with `mpro-api-base` set.
- Slow network: verify skeleton/loading states and no long main-thread blocking.
- PWA: cached core pages load after first visit.

