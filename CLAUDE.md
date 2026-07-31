# mefs-api — backend

Laravel API for [`mefs`](../mefs), a pre-order kitchen. The frontend repo's
[CLAUDE.md](../mefs/CLAUDE.md) holds the shared parameter table, the v1 scope and the
departures from [CLONE_BUILD_BRIEF.md](../mefs/CLONE_BUILD_BRIEF.md). Read that first —
this file covers only what is specific to the server.

## Stack

Laravel 12 · PHP 8.3+ · **PostgreSQL in every environment, including tests** · Sanctum
tokens · Redis queue · Reverb (deferred; realtime matters less when orders are for a future
date).

## The response envelope

Every endpoint returns exactly this, success or failure:

```json
{ "success": true, "message": "…", "data": {}, "errors": {} }
```

`errors` appears only on failure. Two rules, both paid for in the original:

1. **`ApiResponse::success()` takes `(data, message)` and nothing else.** There is
   deliberately no third positional `$status`. The original's helper accepted one, silently
   dropped it, and returned `200` for every creation for months (brief trap §10.12). To
   return another status use the named helper — `created()`, `accepted()`, `error()`.
2. **`error()` takes its status second and required**, so it cannot be forgotten.

Every failure path is enveloped too, including validation, 404, 401, 403 and 500 — the
frontend's error handler reads `message` and `errors` off the body and has nowhere else to
look. That rendering lives in `bootstrap/app.php`.

**The frontend unwraps exactly one level.** `apiClient.get()` resolves to the envelope, so
the payload is `.data` — once. `data.data.x` is `undefined` → `{}` → *looks exactly like
"nothing to report"*. Both of the original's frontend stock-gate bugs had that shape.

## Rules that bite on this side

1. **Never trust a client scope value.** `branch_id`, `staff_id`, `role` are derived from
   the authenticated principal, always. A request that omits a scope parameter returns
   **that caller's scope**, never company-wide totals (brief Law 2).
2. **Scope middleware fails closed.** No route binding to compare against → refuse and log.
   The original's failed *open* and guarded nothing while looking like it did (trap §10.1).
3. **Role ceiling + self-edit guard.** No actor assigns a role at or above their own, and
   nobody edits their own role or permission fields. `manage_employees` with no ceiling was
   a one-request takeover in the original (trap §10.2).
4. **Money is computed here.** Integer minor units (pesewas) on the wire; client totals are
   display only.
5. **One basket-to-order path.** The cycle gate and capacity check live in a single service
   consumed by customer checkout *and* admin manual entry, with a test per path. The
   original gated the endpoint the till didn't use, and a sale went through four minutes
   after the gate deployed (brief §5.8, trap §10.9).
6. **Snapshot, don't join, for anything the customer saw.** Item name and price are copied
   onto `order_items` at order time. A price change must not rewrite last month's receipts.
7. **Order lines key to `menu_item_option_id`** even though IMS is deferred — otherwise v2
   recipes need a migration sweep across every historical order.
8. **Soft-deleted rows still hold unique indexes.** Re-adding a deleted menu option needs
   `withTrashed()->firstOrNew()` (trap §10.5).
9. **Literal routes before wildcards.** `orders/export` must be declared before
   `orders/{order}` or the wildcard eats it (trap §10.7).
10. **Widening an enum means updating its CHECK constraint too** — including on
    `order_status_history`, which is easy to forget (trap §10.11).
11. **Never store a decryptable password.** No `recoverable_password` column. Reset tokens
    only (trap §10.14).

## The order cycle

The unit of planning. One cooking window plus a separate, earlier ordering window.

```
order_cycles     service_start_date … service_end_date   ← when she cooks
                 orders_open_at    … orders_close_at     ← when customers may order
                 override: null | force_open | force_closed
cycle_days       one row per date; is_open, cutoff_at, capacity
cycle_day_items  which dish appears on which day; portion_capacity
```

`App\Services\Ordering\CycleGate` resolves ordering state and is the **only** place that
decides whether an order may be placed. It returns a state *and* a reason, never a bare
boolean. Precedence:

```
force_closed → day closed → force_open → before orders_open_at → past cutoff → capacity → open
```

Manual reopen past the deadline is exactly `force_open` beating the cutoff check. That is
the feature, not a workaround.

`override` is **one nullable column, not two booleans** — `is_force_open` and
`is_force_closed` can both be true, and then nobody knows what the shop is doing.

## Payments

Paystack. Hosted checkout for the customer, webhook for truth.

- A `payments` row **per attempt**, not per order.
- The webhook sits **outside** the auth group (the gateway is not logged in), is verified by
  `x-paystack-signature` HMAC-SHA512, and is **idempotent** — a unique index on the Paystack
  reference makes a replay a no-op rather than a double credit. Assume duplicates.
- A browser redirect is never proof of payment. Verify server-side.
- **Admin-entered orders may hold a slot unpaid** until `slot_hold_expires_at`; a scheduled
  job then releases the capacity and flags the order. Customer-placed orders may not.

## SMS

SMSOnlineGH, behind a driver interface so the `log` driver can stand in locally and in CI —
a test suite must never text a real customer. Messages: order confirmation, cutoff nudge,
ready-for-collection.

**Delivery fees are pass-through, not revenue.** She uses a third-party courier, so the fee
is collected and handed over. `delivery_fee_collection` records who takes it, and every
analytics query excludes pass-through fees. Counting them as income overstates every revenue
figure in the business (brief §5.3).

## Testing

**PostgreSQL, never SQLite.** SQLite folds `LIKE` case and cannot parse `EXTRACT`; a green
SQLite suite does not prove the Postgres path. The original's suite failed 3–6 tests
*depending on the wall-clock hour* for exactly this reason, so a drop in failures meant the
clock had moved, not that anything was fixed (brief §11.2).

**Zero known-failing tests.** A test that cannot pass is skipped with a linked reason, never
left red. A red baseline destroys the signal.

```bash
php artisan test
php artisan test --filter=CycleGate
php artisan migrate:fresh --seed
php artisan serve            # :8000
```

Tests run against the `mefs_testing` database, configured in `phpunit.xml`.

## Local setup

PostgreSQL 18 runs as a Windows service on `:5432`. The `mefs` role owns both databases:

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
```

## Status

**M0 in progress** — scaffold, envelope, `/api/v1/health`, CI. See the milestone table in
the [frontend CLAUDE.md](../mefs/CLAUDE.md).
