# Credit, payment, and manual adjustments workflow

## Definitions (customer table)

- `total_credit`: the customer’s **unpaid credit** from orders (sum of `orders.unpaid_credit` for delivered orders).
- `manual_credit`: **extra unpaid** amount not tied to a specific order (admin-entered), tracked by a ledger table.
- `total_unpaid`: the customer’s **total outstanding balance**:
  - `sum(orders.unpaid_cash) + sum(orders.unpaid_credit) + manual_credit`

## Database setup

1. Run the admin dashboard migration (adds `manual_profit`, `manual_loss`, `manual_credit`, etc):
   - `sql files/admin_dashboard_migration.sql`
2. Run the manual adjustment ledger tables migration:
   - `sql files/customer_adjustments_ledger.sql`

## Order delivery flow (admin clicks “Deliver”)

Endpoint: `api/order/updateDeliverStatus.php`

When `deliverStatus = 6` (Delivered), the backend:

1. Recalculates the order totals from `ordered_list`:
   - `total_price`
   - `cash_amount` / `credit_amount` (credit is limited by `customer.permitted_credit` and current outstanding credit)
2. Preserves any already-paid amount if the order was previously delivered and partially paid:
   - Computes how much was already paid on that order.
   - Re-applies that paid amount to the newly recalculated totals (cash first, then credit).
   - Updates `orders.unpaid_cash` / `orders.unpaid_credit` without “resetting” them to full again.
3. Recalculates the customer totals from source-of-truth:
   - `customer.total_credit` = sum of delivered `orders.unpaid_credit`
   - `customer.total_unpaid` = sum of delivered `orders.unpaid_cash` + sum of delivered `orders.unpaid_credit` + `customer.manual_credit`

## Payment flow (admin records a payment)

Endpoint: `api/order/insertPayment.php`

When a payment is posted, the backend:

1. Applies the payment to delivered orders (`deliver_status = 6`), oldest first:
   - Pays `orders.unpaid_cash` first
   - Then pays `orders.unpaid_credit`
2. If payment money remains, it pays down `customer.manual_credit` (when enabled):
   - Inserts a negative row into `customer_manual_credit_entries` (if the ledger table exists), or updates `customer.manual_credit` directly.
3. Recalculates customer totals (same formula as above) and saves:
   - `customer.total_credit`
   - `customer.total_unpaid` (includes `manual_credit`)
4. Inserts the payment row into `payments` with `credit_left_after_payment` = current `customer.total_unpaid`.

## Manual credit / profit / loss flow (reason-based, by day)

Endpoint: `api/admin/customer_adjustments.php`

This endpoint stores every manual adjustment as an entry with:
- `entry_date` (YYYY-MM-DD)
- `amount` (positive or negative)
- `reason`

Then it updates the running totals in the `customer` table by summing the ledger:
- `customer.manual_credit`
- `customer.manual_profit`
- `customer.manual_loss`

And recalculates `customer.total_unpaid` so it always includes the current `manual_credit`.

The admin dashboard uses a dialog to:
- change customer `segment`
- add manual credit/profit/loss entries with a reason and date
- view the most recent adjustment history

## Order history API change

Endpoint: `api/order/getHistory.php`

- The response field `totaUnpaidCash` now returns `customer.total_unpaid` (from the customer table).

