# Admin Dashboard Plan (MerkatoPro)

This plan is tailored to your current stack (PHP + MySQL/MariaDB + Android/Web client) and your current DB (`sql files/astemaos_mpro.sql`) + notification flow (FCM + AfroMessage SMS) + OpenRouter `google/gemini-3-flash-preview`.

## 1) What to build (Dashboard modules)

### A. Overview (Home)
- Today: orders count, revenue, gross profit, net profit, refunds/returns, unpaid (cash+credit), new customers, active customers.
- This week/month: same KPIs + trends (spark lines).
- Operational: orders by status (`orders.deliver_status`), “deliveries today” by address/sub-city, top 10 selling goods, top 10 customers.

### B. Orders & Delivery Ops
- Orders list with filters: date range, status, address/sub-city, customer, supplier.
- Order detail: items (`ordered_list`), status history, payment history, return history, profit breakdown.
- “Delivery run” view: by address/sub-city (who to notify, who ordered, expected drop-offs).

### C. Customers (LTV / Profit & Loss)
- Customer profile: orders timeline, payments timeline, returns, outstanding credit, preferred categories/brands, activity summary.
- “What I gain / what I lose per customer”:
  - Gain: gross profit from their orders (commission/margin), delivery fees (if applicable).
  - Loss: refunds/returns, SMS cost (optional), bad debt (unpaid past due), promotions/discounts (if any).
- Segmentation: “high value”, “at risk”, “new”, “inactive”, “credit-heavy”, “delivery-heavy”, etc.

### D. Goods & Supplier Performance
- Goods performance: views → cart → order conversion (requires activity + event mapping), profit contribution, stock/back-in-stock interest (`backed_notify`).
- Supplier performance: revenue, profit contribution, availability/stock-outs, lead time (ordered → delivered).

### E. Notifications & SMS Campaigns
- Create campaigns: audience selection (filters + segments + address-based).
- Channel: FCM, SMS (honor `notification_mangement` preferences).
- Scheduling: immediate / scheduled / “when we deliver to address X”.
- Reporting: sent, delivered/failed (best-effort), opens (from `notification_open` activity), attributed orders (simple attribution rules).

### F. AI Insights (OpenRouter)
- “Priority goods” recommendations per customer/segment based on activity + order history.
- “Potential customer” targeting: identify likely-to-buy users and propose the best message + goods highlights.
- Copy generation: localized (Amharic/English) templates with guardrails + approval workflow.

---

## 2) MVP first (2–3 weeks)

### Week 1: Data correctness + KPI queries
1. Define exact KPI formulas (profit, daily profit, gain/loss per customer).
2. Add missing DB fields/tables needed for correct profit and notification attribution (section 3).
3. Build read-only endpoints for dashboard KPIs and lists (orders/customers).

### Week 2: Admin auth + basic UI
1. Secure admin login (hash passwords; session/JWT; role-based access).
2. Build dashboard pages: Overview, Orders, Customers, Notifications (manual send only).

### Week 3: Campaigns + tracking
1. Add campaign creation, audience filters, scheduling.
2. Log every outgoing message (FCM/SMS) and connect opens + orders for ROI.

After MVP: enable AI-assisted segmentation and message generation with “human approval”.

---

## 3) Database changes I recommend (to make the dashboard effective)

Your current schema is close, but **profit** and **notification analytics** are not reliably computable yet.

### A. Fix profit accounting (critical)
Right now `orders.profit` is often `0` (see `api/order/insertOrder.php`) and you don’t snapshot pricing components per line item.

Recommended approach (minimal change, keep existing tables):

1) Add snapshot columns to `ordered_list` so you can compute historical profit even if prices/commission change later:
- `base_price` (supplier price at time of order, ETB)
- `commission` (ETB per unit)
- `final_price` (ETB per unit; should match current `each_price`)
- `line_profit` (ETB = commission * quantity OR final_price - base_price, depending on your model)

2) Add `orders` rollups (computed at order creation or when order is “checked/delivered”):
- `gross_profit` (sum line_profit)
- `delivery_fee` (if you charge it)
- `refund_total`
- `net_profit` (gross_profit + delivery_fee - refunds - sms_cost - other expenses)

3) Add a simple `expenses` table for operational costs (optional but needed for “net profit”):
- `id`, `type` (delivery, salary, sms, rent, etc.), `amount`, `note`, `created_at`

### B. Add notification logs (critical)
You have preferences (`notification_mangement`) but no durable audit/reporting of what was sent.

Add:

1) `message_campaigns`
- `id`, `name`, `channel` (fcm|sms|both), `purpose` (ads|delivering|ordering|price_change|new_product), `created_by_admin_id`, `created_at`, `scheduled_at`, `status`

2) `message_deliveries`
- `id`, `campaign_id`, `customer_id`, `channel`, `title`, `body`, `data_payload_json`, `provider_message_id` (nullable), `provider_status` (nullable), `sent_at`, `error` (nullable)

3) `message_events`
- `id`, `delivery_id`, `event_type` (opened|clicked|failed|unsubscribed), `event_at`, `metadata_json`

Then you can join:
- open events from app (`customer_activity_uploads.type = notification_open`) → `message_events.opened`
- orders placed after send → attributed revenue/profit

### C. Location-based “we are coming” notifications + willingness recording (critical)
You need a way to (1) define a delivery run to an address, and (2) record who wants those alerts.

Add:

1) `delivery_runs`
- `id`, `address_id`, `run_date`, `eta_window` (text), `status`, `created_by_admin_id`, `created_at`

2) `delivery_run_optins`
- `id`, `customer_id`, `address_id`, `opt_in` (bool), `channel_preference` (fcm|sms|both), `updated_at`

3) `delivery_run_notifications`
- `id`, `delivery_run_id`, `campaign_id` (or direct message), `sent_at`

### D. Activity analytics (high value)
You already have `customer_activity_uploads` with rich fields. To make it dashboard-friendly:
- Indexes: (`customer_id`, `started_at`), (`type`, `started_at`), (`target_id`)
- Consider standardizing event types to strings (you already do) and deprecating `user_activity` (looks legacy).

### E. Money type consistency (recommended)
Money columns are mixed (`int` vs `decimal`). Pick one:
- Option 1: store ETB as `INT` (whole ETB)
- Option 2: store cents as `INT` (ETB * 100)
- Option 3: `DECIMAL(12,2)` everywhere

Right now `orders.total_price` is `int`, but `supplier_order.total_price` is `decimal(12,2)`.

---

## 4) AI model integration (OpenRouter + Gemini) for targeting + recommendations

### A. Where AI should live
Do **not** call OpenRouter from Android/Web clients (keys leak). Put AI calls in your backend (PHP), and return only the final, approved campaign/message to clients/admin UI.

### B. Inputs to AI (what you should send to the model)
For each campaign run or personalization job, build a compact JSON “customer feature” object:
- customer: `id`, address/sub-city, last_active, preferred categories/brands, credit usage, unpaid, LTV/profit
- behavior: last 7/30 days activity counts (product_detail, add_to_cart, order_history, search_query, notification_open)
- constraints: user notification prefs (`notification_mangement`), opt-in for delivery-run notifications
- business context: `docs/our_service.md` (or the same text you already use in `api/customer/startChat.php`)

### C. Outputs from AI (what you store)
Store AI outputs for audit + improvement:
- recommended goods IDs (ranked) + reasons
- suggested message title/body + channel
- confidence/priority score
- “do not message” reasons (spam risk, opt-out, inactive, etc.)

Add `ai_recommendation_runs` + `ai_recommendations` tables if you want this fully tracked.

### D. Safety guardrails (important)
- Always require admin approval for bulk sends at first.
- Rate-limit per customer (e.g., max 1–2 promos/week).
- Respect preferences and opt-ins; keep an “unsubscribe” path for SMS.

---

## 5) Questions for you (to finalize formulas + schema)

1) **Profit definition:** Is your profit only **commission** (e.g., `goods.commission`), or do you also earn from **delivery fees**, **supplier discounts**, etc.?
2) **Delivery fee storage:** Where is delivery fee recorded per order today (if anywhere)? If not recorded, do you want it on `orders`?
3) **Supplier cost:** For an order line, what is the true “cost” you pay the supplier—`supplier_goods.price` at order time, or something else?
4) **When to count profit:** On `order_time`, on `deliver_time`, or only when paid (`payments`)?
5) **Bad debt:** When do you consider unpaid credit/cash as “loss” (after due_date from `credit`, after X days, manually)?
6) **Returns/refunds:** When a return happens (`return_order`, `returnOrderList`), do you refund cash, reduce credit, or replace goods? How do you want that reflected?
7) **Customer types:** How do you define “type” today—by `customer.user_type`, by order size/frequency, by location, or by business category?
8) **Potential customers:** Are “potential customers” already in `customer`, or do you also want to store leads who haven’t registered? If yes, where do leads come from?
9) **Address targeting:** Should targeting use `address_id` only, or also precise `latitude/longitude` radius targeting?
10) **Attribution:** For campaign ROI, do you want “orders within 24h after send”, “first click/open then order”, or something else?

If you answer these, I can propose the exact SQL migrations + dashboard KPI queries, and the exact campaign + AI workflow (including a prompt template and JSON schema).

