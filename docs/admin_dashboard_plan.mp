# Admin Dashboard Plan (MerkatoPro) — Finalized

This plan is tailored to your current stack (PHP + MySQL/MariaDB + Android/Web client), the existing DB (`sql files/astemaos_mpro.sql`), FCM + AfroMessage SMS, and OpenRouter `google/gemini-3-flash-preview`. It reflects your answers and new requirements.

## 1) Product goals (what the dashboard must deliver)

- Easy-to-use, attractive admin dashboard with clear daily KPIs.
- AI runs in the background to **bring customers** and **keep customers**.
- Promotional campaigns: **at least 100 SMS + 100 FCM** per promo run (if audience is large enough).
- All scheduling respects **Ethiopian time** (`Africa/Addis_Ababa`).
- Manual controls: delivery time entry, expenses, customer credit/profit/loss adjustments, and lead capture.

---

## 2) KPI definitions (final)

- **Profit** = sum of **commission per goods** (from `goods.commission` snapshot at order time).
- **Daily profit** = sum of `orders.profit` **by order_time** for orders with `deliver_status IN (4,5,6)`.
- **Daily revenue** = sum of `orders.total_price` by order_time, excluding cancelled.
- **Daily supplier cost** = sum of `ordered_list.supplier_price * quantity` by order_time for `deliver_status IN (4,5,6)`.
- **Daily expenses** = manual entries (reason + amount).
- **Total credit** = `customer.total_credit + customer.manual_credit` (manual edit allowed).
- **Customer loss/profit** = computed profit + manual profit/loss adjustments.
- **Returns/refunds** = tracked manually for now.

---

## 3) Dashboard modules (final scope)

### A. Overview
- Daily cards: profit, revenue, expenses, supplier cost, new customers registered, new customers ordered.
- Trend chart: last 7 days (profit/revenue/expenses).
- Status snapshot: orders by status (optional).

### B. Customers
- Segment management: `new`, `active`, `loyal`, `vip`, `at_risk`, `churned`, `potential`.
- Customer profit/loss adjustments (manual).
- Customer credit adjustments (manual).

### C. Promotions (FCM + SMS)
- Build campaigns by **segment** or **address**.
- Min recipients for promotional: **100** (blocks send if fewer).
- Respect notification preferences (`notification_mangement`).
- Ethiopian time scheduler.

### D. Delivery Runs
- Manual delivery time entry per `address_id`.
- Opt-in table for “notify me when you deliver to my address”.

### E. Leads / Potential Customers
- Manual lead entry: name, phone, source (TikTok, Telegram, Google Map).
- Stored as customers with `segment='potential'` and `is_lead=1`.

### F. Inventory (manual goods)
- Store **returns** and **bulk purchases** with manual entry.
- Simple ledger for manual inventory entries.

---

## 4) Category focus (priority)

Customers focus mainly on **smartphone prices**. Category IDs:
- charger=1, cover=2, airpods=3, smart phone=4, glass=5, flash&memory=6,
  button phone=7, battery=8, speaker=9, screen=10, earphones=11, other accessories=12

AI prompts should prioritize category **4 (smart phone)** and price clarity.

---

## 5) Database changes (final)

### A. Profit snapshots per order line
- `ordered_list.supplier_price` (supplier cost at order time)
- `ordered_list.commission` (per unit profit)
- `ordered_list.line_profit` (commission * quantity)

### B. Orders
- Ensure `orders.profit` is computed at order creation and updated when deliver_status changes.

### C. Customer manual controls + segmentation
- `customer.segment` (varchar)
- `customer.manual_profit`, `customer.manual_loss`, `customer.manual_credit`
- `customer.is_lead`, `customer.lead_source`

### D. Expenses (daily)
- `daily_expenses` table (amount + reason + date)

### E. Promotions
- `message_campaigns`, `message_deliveries`, `message_events`

### F. Delivery runs
- `delivery_runs`, `delivery_run_optins`

### G. Inventory ledger
- `inventory_entries` table (bulk, return, manual adjustments)

---

## 6) AI background workflow (final)

**Goal:** run automatically to bring/retain customers.

1. Nightly AI job creates **promotional campaigns**:
   - Segment: `potential`, `at_risk`, `churned`
   - Channels: SMS + FCM
   - Min recipients: 100 each
2. Prompt includes:
   - `docs/our_service.md` context
   - top smartphone goods (category 4)
   - segment name + goals (re-activate, convert, retain)
3. AI outputs:
   - title + body
   - recommended goods IDs
4. Campaigns are scheduled for Ethiopian time.

---

## 7) Implementation notes (what I will build)

- SQL migration file for new tables/columns.
- Profit snapshots + updates in order creation/update flows.
- Admin dashboard HTML with a clean, modern UI.
- Admin endpoints for:
  - overview KPIs
  - expenses
  - customers (segment + manual edits)
  - leads (potential customers)
  - campaigns + dispatch
  - delivery runs
  - inventory entries
- Background AI script (OpenRouter) + scheduler-ready.

