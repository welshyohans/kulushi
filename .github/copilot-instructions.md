## Quick orientation for AI coding agents

This PHP backend is a small REST-style API arranged under `api/` with thin endpoint scripts and procedural controllers that use PDO-backed model classes in `model/` and a single `config/Database.php` for DB connection.

Key facts (read before editing code):

- Endpoints live in `api/<domain>/` (e.g. `api/customer/login.php`, `api/supplier/login.php`, `api/order/insertOrder.php`). Each endpoint:
  - expects JSON POST bodies (most files check `$_SERVER['REQUEST_METHOD'] === 'POST'` and parse `php://input`).
  - sets `Content-Type: application/json` and returns JSON payloads with a top-level `success` boolean and domain-specific fields (e.g. `customer_id`, `supplier_id`, `order_id`).
  - often return HTTP 200 with `success: false` for domain errors (see `api/customer/login.php`) — follow the existing pattern when modifying behavior.

- Database connection: `config/Database.php` constructs a PDO connection reading env variables (DB_HOST, DB_USER, DB_PASS, DB_NAME). Some files load environment variables via `load_env.php` before constructing `Database`.

- Models accept a PDO connection in the constructor (e.g. `new Goods($conn)`, `new SupplierGoods($conn)`) and provide single-responsibility methods. They throw `InvalidArgumentException` for missing required fields (see `model/Goods.php` and `model/SupplierGoods.php`).

- Important domain conventions:
  - Tables and fields used frequently: `supplier` (key: `shop_id`), `goods` (`id`), `supplier_goods` (relations, `last_update_code`, `is_available`, pricing fields).
  - `last_update_code` is used as a synchronization/versioning token. When creating or updating supplier-related rows, call model helpers that update `last_update_code` and call `touchSupplierLastUpdate` where appropriate (see `model/SupplierGoods::upsertRelation`, `updatePrice`, `updateAvailability`).

- Transactions: multi-row operations (e.g. `api/order/insertOrder.php`) use `$conn->beginTransaction()`, `rollBack()` on failure and `commit()` on success. Preserve transaction boundaries when refactoring.

Examples to follow when editing or adding endpoints

- Add a simple POST endpoint: mirror the style in `api/supplier/login.php`:
  - check method, parse `php://input`, validate required fields, use `Database` to get PDO, use prepared statements, and return JSON with `success` and identifiers.

- Add/update supplier goods: use `model/SupplierGoods` methods rather than writing raw SQL. These helpers enforce required fields and update `last_update_code` correctly.

Developer workflow notes (discoverable/assumed)

- There is no build/test system in the repo. To run the API locally for manual testing, a reasonable assumption is to use PHP's built-in server from the repo root (adjust document root if you host differently):

  php -S localhost:8000 -t api

  (Assumption: user runs PHP 7.4+ and maps requests to `api/` as doc root. Confirm if you use Docker, Apache or another host.)

What NOT to change without confirmation

- Global response shapes and status codes (many endpoints return 200 for application-level errors). Changing these affects clients — ask the owner before altering.
- The `last_update_code` pattern and `touchSupplierLastUpdate` flow — changing this requires coordination with consumers of update feeds.

Files to inspect for context when editing

- `config/Database.php` — DB connection and env usage.
- `api/order/insertOrder.php` — example of transaction handling and how `Orders` and `OrderedList` models are used together.
- `model/SupplierGoods.php` and `model/Goods.php` — canonical data-access patterns and field requirements.

If anything in this file is unclear or you want conventions for other tasks (tests, CI, or running under Docker), tell me which area to expand and I'll update this file.
