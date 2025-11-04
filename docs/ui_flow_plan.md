# UI Flow and Layout Plan

## 1. Authentication & Routing Logic

- The entry point is `login.html`.
- Successful authentication:
  - POST to `api/customer/login.php` with `{ phone, password }`.
  - On success, persist `{ customerId, phone }` to `localStorage` key `mproSession`.
  - Redirect to `index.html`.
- On any visit to `index.html`, perform session bootstrap:
  - Read `mproSession`.
  - If absent or malformed, redirect to `login.html`.
  - Perform `/api/customer/profile.php` (new) using `customerId` to validate registration status.
  - If backend reports not registered (e.g., `success: false` or missing profile), clear `mproSession` and redirect to `login.html`.

## 2. Login Screen (login.html)

### Layout

- Split layout:
  - Centered card on desktop (max width ~420px).
  - Full-width form with generous padding on mobile.
- Fields:
  - Phone input (pattern validation, numeric keypad).
  - Password input (toggle visibility button).
- Controls:
  - Submit button with loading indicator.
  - Link/button for "Forgot password" (optional stub linking to existing flow if available).
- Feedback:
  - Inline validation messages under inputs.
  - Alert banner for authentication failure.

### Additional UX

- Detect Android user-agent:
  - Show top `Download our App` callout (full width bar) linking to `app/merkatopro.apk`.
  - Modal prompt after successful login offering download (dismissible, persists acceptance in localStorage).

## 3. Main Dashboard (index.html)

### Responsive Navigation

- Breakpoint at `min-width: 992px` (Bootstrap `lg`):
  - **Desktop (`≥992px`)**: Fixed left navigation rail (Home, Categories, Suppliers, Profile). Content area to the right.
  - **Mobile (`<992px`)**: Bottom navigation bar with icons + labels. Floating toolbars where necessary.
- Top bar:
  - Brand/logo placeholder.
  - Cart icon (button). When clicked, opens cart dialog overlay.
  - Optional search input (desktop only) for goods.

### Sections / Screens

1. **Home**
   - Hero area with promotional info or stats.
   - Goods grid (lazy-loaded images):
     - Card elements: image, name, price, supplier name, `See options` button, `Add to cart` button (icon + label).
     - Card click opens detailed dialog with carousel/large image, description, comments (list view).
     - `See options` opens options dialog listing all supplierGoods entries for this goods with min order, price, add to cart actions.
     - Lazy load more goods via infinite scroll or `Load more` button (calls API with pagination).

2. **Categories**
   - Horizontal chips (mobile) or left column list (desktop).
   - Selecting category filters goods grid.

3. **Suppliers**
   - Supplier cards/list (image, name, type, phone).
   - Select supplier to see goods filtered to that supplier.
   - For `shop_type === 'self-delivered'`, disable add-to-cart entries and show toast "Visit his shop".

4. **Profile**
   - Displays customer profile info (name, shop, addresses).
   - Buttons for logout and edit actions (edit may be stub).

### Data Fetching

Initial load (after session validation):
- Parallel fetch to:
  - `api/dashboard/getFeaturedGoods.php` (new) returning top ~20 goods prioritized by priority + cheapest supplier price.
  - `api/dashboard/getCategories.php`.
  - `api/dashboard/getSuppliers.php` (can reuse existing `api/getSupplier.php` but filter isVisible).
  - `api/customer/getProfile.php`.
- Store responses in state manager (JS module using observers).

Subsequent interactions:
- Category click -> fetch `api/dashboard/getGoodsByCategory.php?categoryId=...`.
- Supplier click -> fetch `api/dashboard/getGoodsBySupplier.php?supplierId=...`.
- `See options` -> fetch `api/dashboard/getSupplierGoodsOptions.php?goodsId=...`.
- Comments -> fetch `api/comment/getComments.php?goodsId=...`.

### Cart System

- Local storage key `mproCartItems` storing array of `{ supplierGoodsId, goodsId, quantity, minOrder, price, supplierInfo }`.
- Cart dialog content:
  - List items with image, name, supplier.
  - Quantity stepper (min order enforced).
  - Remove button (trash icon).
  - Totals summary.
  - Checkout button calling `api/order/createFromCart.php` (new stub).
- Add-to-cart button states:
  - Default state: `Add to cart` icon button.
  - After adding: quantity controls (`-` `Qty` `+`).
  - For `self-delivered` suppliers: show toast and disable action.

### Dialogs

- Use Bootstrap modal for:
  - Goods detail (with image carousel, description, comments list, add-to-cart integration).
  - See options (list of supplierGoods).
  - Cart summary.
- Ensure modals are accessible (focus trap, close buttons).
- Lazy load images inside modals with `loading="lazy"`.

### Toast Notifications

- Implement global toast container (Bootstrap 5) for:
  - Add to cart success.
  - Visit shop warning.
  - Network errors.

### Android CTA

- On Android user agent:
  - Topbar full-width banner with `Download our App` button (visible on all screens until dismissed).
  - After dismissal, store `mproAndroidBannerDismissed` flag.

### Performance & UX

- Skeleton loaders for initial fetch.
- Intersection Observer for lazy loading cards.
- Debounced search (if implemented).
- Handle API errors with fallback UI.

## 4. Component / Module Structure (Front-End JS)

- `session.js`: handles login session storage, validation, redirect helpers.
- `apiClient.js`: wrapper for fetch with base URL, error handling.
- `state.js`: simple pub/sub store for goods, categories, suppliers, cart.
- `ui/navigation.js`: handles responsive nav toggles.
- `ui/goodsGrid.js`: renders goods cards, updates on filters.
- `ui/cart.js`: handles cart dialog, localStorage sync.
- `ui/dialogs.js`: goods detail & options handling.
- `util/platform.js`: detect Android, responsive helpers.
- `bootstrap.js`: entry point sets up event listeners, fetch initial data.

## 5. Backend API Endpoints (to add)

Under `api/dashboard/` (new directory):

1. `getFeaturedGoods.php`
   - Params: optional `limit` (default 20).
   - Query: join goods + supplier_goods to get cheapest price per goods.
   - Response: goods array with aggregated info.

2. `getGoodsByCategory.php`
   - Params: `categoryId`, optional `limit`/`offset`.
   - Response: goods array.

3. `getGoodsBySupplier.php`
   - Params: `supplierId`.
   - Response: supplier goods list with goods info.

4. `getSupplierGoodsOptions.php`
   - Params: `goodsId`.
   - Response: list of supplier_goods entries with price, min order, supplier details.

Under `api/customer/`:

5. `profile.php`
   - Params via session (POST JSON `{ customerId }`).
   - Response: `success`, `profile` (name, phone, shop, addresses).
   - Use `customer` table.

6. `logout.php` (optional)
   - Clears session token if server-managed.

Under `api/order/`:

7. `createFromCart.php`
   - POST `{ customerId, items: [{ supplierGoodsId, quantity }] }`.
   - Validate min order, supplier availability.
   - Return summary; actual order insertion can be stubbed if outside scope.

Shared:

- Consider `api/dashboard/getCategories.php` to expose available categories (only is_available = 1).
- Consider `api/dashboard/getActiveSuppliers.php` filtering `isVisible = 1`.

## 6. Redirect & Access Control Summary

- All API endpoints should validate input using prepared statements.
- `index.html` bootstrap script enforces session before loading UI.
- Logout clears `mproSession` and `mproCartItems`.

## 7. Accessibility & Internationalization

- Ensure ARIA labels on navigation and modals.
- Provide alt text for images (fallback to goods name).
- Currency displayed as ETB with Intl formatter.

## 8. Pending Decisions

- API pagination strategy (offset vs cursor) — default to limit/offset for 20 items.
- Comments endpoint ensures sanitized content; might require server update if numeric placeholder.
- Determine how to map `supplierGoods` cheapest price (subquery or group by).
- Confirm toast design (Bootstrap 5 vs custom).
