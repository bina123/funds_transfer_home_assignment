# Paysera — Fund Transfer API

A production-grade REST API for transferring funds between accounts, built with a **Modular Monolith** architecture following **Domain-Driven Design (DDD)** and **SOLID** principles.

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.4 (`declare(strict_types=1)` everywhere) |
| Framework | Symfony 7.4 |
| Database | MySQL 8.0 |
| Cache / Rate limiter | Redis 7 |
| ORM | Doctrine ORM with Optimistic Locking |
| Tests | PHPUnit (Integration tests against real DB) |
| Infrastructure | Docker + Nginx |

---

## Quick Start

```bash
# 1. Clone the repository
git clone <repository-url>
cd paysera_home_assignment

# 2. One-command setup (build, start, install deps, migrate, seed fixtures)
make setup

# API is now available at http://localhost:8080
```

Or step by step:

```bash
make build      # Build Docker images
make up         # Start containers
make install    # composer install
make migrate    # Run DB migrations
make fixtures   # Load seed accounts (EUR x2, USD x1)
```

---

## Running Tests

```bash
make test
```

This creates the test database (`paysera_test`), runs migrations against it, then executes all integration tests. Tests run against a real MySQL instance — no mocks.

---

## API Reference

### POST /api/v1/transfers — Create a transfer

```bash
curl -X POST http://localhost:8080/api/v1/transfers \
  -H "Content-Type: application/json" \
  -d '{
    "from_account_id": "018f3d2e-7c4b-7a2b-8a4d-1b2c3d4e5f6a",
    "to_account_id":   "018f3d2e-9f12-7b3c-9d5e-2c3d4e5f6a7b",
    "amount": 2500,
    "currency": "EUR",
    "idempotency_key": "550e8400-e29b-41d4-a716-446655440000"
  }'
```

**Fields:**

| Field | Type | Description |
|---|---|---|
| `from_account_id` | string (UUID) | Source account UUID |
| `to_account_id` | string (UUID) | Destination account UUID |
| `amount` | integer | Amount in **minor units** (cents). `2500` = €25.00 |
| `currency` | string | ISO 4217 code, e.g. `EUR`, `USD` |
| `idempotency_key` | string (UUID v4) | Client-generated UUID v4, unique per intended transfer. Same key → same result, no double debit |

**Success — 201 Created:**
```json
{
  "id": "018f3d2f-1a2b-7c3d-8e4f-5a6b7c8d9e0f",
  "idempotencyKey": "550e8400-e29b-41d4-a716-446655440000",
  "fromAccountId": "018f3d2e-7c4b-7a2b-8a4d-1b2c3d4e5f6a",
  "toAccountId": "018f3d2e-9f12-7b3c-9d5e-2c3d4e5f6a7b",
  "amount": 2500,
  "currency": "EUR",
  "status": "completed",
  "failureReason": null,
  "reversalOfTransferId": null,
  "reversedBy": null,
  "createdAt": "2026-03-19T10:00:00+00:00"
}
```

> Retrying with the same `idempotency_key` returns the original response — no second debit.

**Business failure — 422 Unprocessable Entity:**

When a transfer is rejected (insufficient funds, currency mismatch, suspended account, limit exceeded), the API returns 422 **and** persists a `status: "failed"` Transfer record for audit trail and fraud detection. The original `idempotency_key` is **not consumed** — the client may reuse it after resolving the underlying issue (e.g. topping up the balance).

```json
{
  "error": "insufficient_funds",
  "message": "Insufficient funds."
}
```

The failed Transfer row is visible in the transfer history via `?status=failed` and contains `failureReason` for support and fraud analysis.

**How to generate an idempotency key:**

Generate a UUID v4 **once**, before the first attempt. Reuse the same key on every retry of that operation. Never reuse a key for a different payment.

```php
// PHP
$idempotencyKey = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
// → "a1b2c3d4-e5f6-4a1b-8c2d-1f2e3d4c5b6a"
```

```javascript
// JavaScript (Node 15+ / all modern browsers)
const idempotencyKey = crypto.randomUUID();
```

```python
# Python
import uuid
idempotency_key = str(uuid.uuid4())
```

```bash
# Shell
uuidgen | tr '[:upper:]' '[:lower:]'
```

> Do **not** fetch the key from the server — that adds a round-trip and defeats the purpose. The key must exist on the client before the first attempt so it survives a network failure and can be attached to every retry.

---

### POST /api/v1/accounts — Create an account

```bash
curl -X POST http://localhost:8080/api/v1/accounts \
  -H "Content-Type: application/json" \
  -d '{"currency": "EUR", "initial_balance": 10000}'
```

| Field | Type | Required | Description |
|---|---|---|---|
| `currency` | string | Yes | ISO 4217 3-letter code, e.g. `EUR`, `USD` |
| `initial_balance` | integer | No | Starting balance in minor units (cents). Defaults to `0` |

**Success — 201 Created:**
```json
{
  "id": "018f3d2e-7c4b-7a2b-8a4d-1b2c3d4e5f6a",
  "currency": "EUR",
  "status": "active",
  "balance": 10000,
  "balanceFormatted": "100.00",
  "createdAt": "2026-03-20T10:00:00+00:00",
  "updatedAt": "2026-03-20T10:00:00+00:00"
}
```

---

### POST /api/v1/accounts/{uuid}/suspend — Suspend an account

Blocks all incoming and outgoing transfers. Used by compliance teams when a KYC/AML check fails or fraud is suspected. Idempotent — suspending an already-suspended account returns 200.

```bash
curl -X POST http://localhost:8080/api/v1/accounts/018f3d2e-7c4b-7a2b-8a4d-1b2c3d4e5f6a/suspend
```

Returns `200 OK` with the updated account. Returns `422` if the account is closed.

---

### POST /api/v1/accounts/{uuid}/activate — Re-activate a suspended account

Restores full transfer capability after a compliance hold is cleared. Idempotent — activating an already-active account returns 200.

```bash
curl -X POST http://localhost:8080/api/v1/accounts/018f3d2e-7c4b-7a2b-8a4d-1b2c3d4e5f6a/activate
```

Returns `200 OK` with the updated account. Returns `422` if the account is closed (closed accounts are permanent).

---

### POST /api/v1/accounts/{uuid}/close — Close an account permanently

Closes an account forever. Closure is **irreversible** — a closed account can never be re-opened or re-used. A new account must be opened instead.

```bash
curl -X POST http://localhost:8080/api/v1/accounts/018f3d2e-7c4b-7a2b-8a4d-1b2c3d4e5f6a/close
```

Returns `200 OK` with the updated account (`status: "closed"`). Idempotent — closing an already-closed account returns 200.

**Guards:**
- `404` — account does not exist
- `422 domain_rule_violated` — account has a non-zero balance (transfer the remaining balance out first)

---

### GET /api/v1/accounts/{uuid} — Get account details

```bash
curl http://localhost:8080/api/v1/accounts/018f3d2e-7c4b-7a2b-8a4d-1b2c3d4e5f6a
```

**Success — 200 OK:**
```json
{
  "id": "018f3d2e-7c4b-7a2b-8a4d-1b2c3d4e5f6a",
  "currency": "EUR",
  "status": "active",
  "balance": 7500,
  "balanceFormatted": "75.00",
  "createdAt": "2026-03-19T10:00:00+00:00",
  "updatedAt": "2026-03-19T10:05:00+00:00"
}
```

---

### GET /api/v1/transfers/{uuid} — Get a transfer by UUID

```bash
curl http://localhost:8080/api/v1/transfers/018f3d2f-1a2b-7c3d-8e4f-5a6b7c8d9e0f
```

Returns the full transfer record. `status` is `completed` or `failed`. If the transfer has been reversed, `reversedBy` contains the UUID of the reversal transfer (the original status stays `completed` — append-only ledger).

---

### POST /api/v1/transfers/{uuid}/reverse — Reverse a completed transfer

Refunds the full amount back to the original sender by creating a **new** transfer in the opposite direction. The original transfer record is **never modified** — it stays `completed` forever (append-only ledger). After reversal, `GET /transfers/{uuid}` on the original will return `reversedBy` set to the reversal transfer's UUID.

```bash
curl -X POST http://localhost:8080/api/v1/transfers/018f3d2f-1a2b-7c3d-8e4f-5a6b7c8d9e0f/reverse
```

**Success — 201 Created** (the reversal transfer):
```json
{
  "id": "018f3d30-2b3c-7d4e-9f5a-6b7c8d9e0f1a",
  "idempotencyKey": "reversal_of_7",
  "fromAccountId": "018f3d2e-9f12-7b3c-9d5e-2c3d4e5f6a7b",
  "toAccountId": "018f3d2e-7c4b-7a2b-8a4d-1b2c3d4e5f6a",
  "amount": 2500,
  "currency": "EUR",
  "status": "completed",
  "failureReason": null,
  "reversalOfTransferId": "018f3d2f-1a2b-7c3d-8e4f-5a6b7c8d9e0f",
  "reversedBy": null,
  "createdAt": "2026-03-20T11:00:00+00:00"
}
```

**Original after reversal** — status unchanged, `reversedBy` populated:
```json
{
  "id": "018f3d2f-1a2b-7c3d-8e4f-5a6b7c8d9e0f",
  "status": "completed",
  "reversalOfTransferId": null,
  "reversedBy": "018f3d30-2b3c-7d4e-9f5a-6b7c8d9e0f1a"
}
```

**Guards:**
- `404` — original transfer does not exist
- `409 transfer_already_reversed` — transfer has already been reversed
- `422 transfer_not_reversible` — transfer is not in `completed` status (e.g. it is a `failed` transfer — nothing moved, nothing to refund)

> Reversal idempotency is guaranteed by the auto-generated key `"reversal_of_{id}"` combined with the database `UNIQUE INDEX` — it is physically impossible to create two reversals of the same transfer.

---

### GET /api/v1/accounts/{uuid}/transfers — Transfer history with filters

```bash
curl "http://localhost:8080/api/v1/accounts/018f3d2e-7c4b-7a2b-8a4d-1b2c3d4e5f6a/transfers?direction=sent&currency=EUR&status=completed&from_date=2026-01-01&to_date=2026-12-31&limit=20&offset=0"
```

Returns all transfers where the account is sender or receiver, ordered by most recent first.

**Query params:**

| Param | Default | Description |
|---|---|---|
| `limit` | 50 (max 100) | Number of results |
| `offset` | 0 | Pagination offset |
| `direction` | — | `sent` = outgoing only, `received` = incoming only |
| `currency` | — | Filter by currency, e.g. `EUR` |
| `status` | — | `completed` or `failed` |
| `from_date` | — | Include transfers on or after this date (any parseable date string) |
| `to_date` | — | Include transfers on or before this date |

**Success — 200 OK:**
```json
{
  "data": [
    {
      "id": "018f3d2f-1a2b-7c3d-8e4f-5a6b7c8d9e0f",
      "idempotencyKey": "a1b2c3d4-e5f6-4a1b-8c2d-000000000001",
      "fromAccountId": "018f3d2e-7c4b-7a2b-8a4d-1b2c3d4e5f6a",
      "toAccountId": "018f3d2e-9f12-7b3c-9d5e-2c3d4e5f6a7b",
      "amount": 1000,
      "currency": "EUR",
      "status": "completed",
      "failureReason": null,
      "reversalOfTransferId": null,
      "reversedBy": null,
      "createdAt": "2026-03-19T10:05:00+00:00"
    }
  ],
  "total": 1,
  "limit": 50,
  "offset": 0
}
```

---

### GET /api/v1/accounts/{uuid}/ledger — Account ledger (double-entry bookkeeping)

```bash
curl "http://localhost:8080/api/v1/accounts/018f3d2e-7c4b-7a2b-8a4d-1b2c3d4e5f6a/ledger?limit=20&offset=0"
```

Returns all balance change events for this account, most recent first. Every transfer creates two entries — a `debit` on the sender and a `credit` on the receiver. `balanceAfter` is a snapshot of the account balance immediately after the entry was applied, enabling full balance history reconstruction.

**Success — 200 OK:**
```json
{
  "data": [
    {
      "id": 1,
      "accountId": 1,
      "transferId": 3,
      "type": "debit",
      "amount": 1000,
      "amountFormatted": "10.00 EUR",
      "currency": "EUR",
      "balanceAfter": 9000,
      "balanceAfterFormatted": "90.00 EUR",
      "createdAt": "2026-03-19T10:05:00+00:00"
    }
  ],
  "total": 1,
  "limit": 50,
  "offset": 0
}
```

---

### GET /health — Liveness probe

```bash
curl http://localhost:8080/health
```

Returns `200 OK` as long as the PHP process is running. Used by Docker/Kubernetes to decide whether to **restart** a container.

### GET /health/ready — Readiness probe

```bash
curl http://localhost:8080/health/ready
```

Verifies the service can serve traffic by pinging the database. Returns `200 OK` when the DB is reachable, `503 Service Unavailable` otherwise. Used by load balancers to decide whether to **route traffic** to this instance.

```json
{ "status": "ok", "db": "ok" }
```

> Liveness and readiness are kept separate: a transient DB blip sets the instance to "not ready" (stops new traffic) without triggering an unnecessary container restart.

---

### Error Responses

All errors follow the same JSON structure:

```json
{
  "error": "error_code",
  "message": "Human-readable description."
}
```

| HTTP | `error` code | When it happens |
|---|---|---|
| 400 | `invalid_json` | Request body is not valid JSON |
| 400 | `validation_failed` | Missing/invalid fields (includes `details` array) |
| 404 | `account_not_found` | Account ID does not exist |
| 404 | `transfer_not_found` | Transfer ID does not exist |
| 409 | `transfer_conflict` | Concurrent modification — retry after `Retry-After` seconds |
| 409 | `transfer_already_reversed` | Transfer has already been reversed |
| 422 | `transfer_not_reversible` | Transfer is not `completed` (e.g. `failed` — nothing moved) |
| 422 | `insufficient_funds` | Source account balance is too low |
| 422 | `currency_mismatch` | Transfer currency doesn't match account currency |
| 422 | `account_suspended` | Account is suspended or closed |
| 422 | `domain_rule_violated` | Business rule violation (e.g. activating a closed account) |
| 422 | `transfer_limit_exceeded` | Amount exceeds per-transfer or daily outgoing limit (includes `limit_type` and `limit_amount`) |
| 429 | `rate_limit_exceeded` | Too many requests — see `Retry-After` header |
| 500 | `internal_error` | Unexpected server error (details visible in `dev` env only) |

**Validation error example — 400:**
```json
{
  "error": "validation_failed",
  "message": "Request validation failed.",
  "details": [
    { "field": "amount", "message": "Amount must be a positive integer (in minor units, e.g. cents)." },
    { "field": "currency", "message": "Currency must be a 3-letter ISO 4217 code (e.g. EUR, USD)." }
  ]
}
```

**Rate limit response includes `Retry-After` header:**
```
HTTP/1.1 429 Too Many Requests
Retry-After: 42
```

**Conflict response includes `Retry-After: 1` header** — retry after 1 second.

---

## Architecture

### Modular Monolith with DDD

Each business domain is a fully self-contained **module** with its own Domain, Application, and Infrastructure layers — identical boundaries to microservices, without the operational overhead. When a module needs to scale independently, only its Infrastructure layer changes (Doctrine → HTTP client).

```
src/
├── Module/
│   ├── Account/
│   │   ├── Domain/
│   │   │   ├── Account.php                    # Aggregate root
│   │   │   ├── Money.php                      # Value object (immutable, self-validating)
│   │   │   ├── LedgerEntry.php                # Double-entry bookkeeping entity
│   │   │   ├── AccountRepositoryInterface.php # Domain interface (DIP)
│   │   │   ├── LedgerRepositoryInterface.php  # Domain interface (DIP)
│   │   │   ├── Exception/
│   │   │   │   ├── InsufficientFundsException.php
│   │   │   │   ├── CurrencyMismatchException.php
│   │   │   │   └── AccountSuspendedException.php
│   │   │   └── Event/
│   │   │       ├── AccountCreatedEvent.php
│   │   │       ├── AccountSuspendedEvent.php
│   │   │       ├── AccountActivatedEvent.php
│   │   │       └── AccountClosedEvent.php
│   │   ├── Application/
│   │   │   ├── Command/
│   │   │   │   ├── CreateAccountCommand.php
│   │   │   │   └── CreateAccountCommandHandler.php
│   │   │   └── Query/
│   │   │       ├── AccountResponse.php        # Output DTO (static factory)
│   │   │       └── LedgerEntryResponse.php    # Output DTO for ledger entries
│   │   └── Infrastructure/Persistence/
│   │       ├── DoctrineAccountRepository.php
│   │       └── DoctrineLedgerRepository.php
│   │
│   └── Transfer/
│       ├── Domain/
│       │   ├── Transfer.php                   # Aggregate root (immutable ledger entry)
│       │   ├── TransferFilter.php             # Value object for history query filters
│       │   ├── TransferRepositoryInterface.php
│       │   └── Event/
│       │       ├── TransferCompletedEvent.php
│       │       ├── TransferFailedEvent.php
│       │       └── TransferReversedEvent.php
│       ├── Application/
│       │   ├── Command/
│       │   │   ├── TransferCommand.php        # Input DTO (validated)
│       │   │   ├── TransferCommandHandler.php # Use case / orchestrator
│       │   │   ├── ReversalCommand.php        # Input DTO for reversals
│       │   │   └── ReversalCommandHandler.php # Reversal use case
│       │   └── Query/
│       │       └── TransferResponse.php       # Output DTO (static factory)
│       └── Infrastructure/Persistence/
│           └── DoctrineTransferRepository.php
│
├── Shared/
│   ├── Exception/                             # Cross-module HTTP-mapped exceptions
│   │   ├── ApiException.php
│   │   ├── AccountNotFoundException.php
│   │   ├── TransferNotFoundException.php
│   │   ├── TransferConflictException.php
│   │   ├── TransferAlreadyReversedException.php
│   │   ├── TransferNotReversibleException.php
│   │   └── RateLimitExceededException.php
│   └── EventListener/
│       ├── ExceptionListener.php              # Maps all exceptions to JSON responses
│       ├── RateLimitListener.php              # Per-IP rate limiting
│       ├── TransferCompletedListener.php      # Email + fraud score on success
│       ├── TransferFailedListener.php         # Notification + fraud flag on failure
│       └── AccountLifecycleListener.php       # Welcome email, KYC, compliance alerts
│
├── Controller/Api/V1/                         # Thin HTTP layer — parse, delegate, respond
│   ├── TransferController.php
│   ├── AccountController.php
│   └── HealthController.php
│
└── DataFixtures/                              # Seed data for development
```

### Layer rules — dependencies point inward only

```
Presentation (Controller)
        ↓
Application (CommandHandler, DTOs)
        ↓
Domain (Entities, Value Objects, Interfaces)
        ↑
Infrastructure (Doctrine implements Domain interfaces)
```

---

## Key Design Decisions

### Money as a Value Object
`Money(int $amount, string $currency)` is immutable and self-validating. Amount and currency always travel together — you cannot accidentally pass an EUR amount to a USD account. Amounts are in **minor units** (cents) to avoid IEEE 754 floating-point errors (`0.1 + 0.2 ≠ 0.3` in PHP floats).

### Rich Domain Model
Business rules live in the domain entity, not in services:
```php
$fromAccount->debit($money);  // validates status, currency, balance — then changes itself
$toAccount->credit($money);   // validates status and currency — then changes itself
```
Nothing outside `Account` can modify the balance without passing through these guards.

### Account Status Lifecycle
Accounts have a `status` field: `active` → `suspended` → `closed`.
- `active` — normal operation
- `suspended` — compliance hold (KYC/AML); no transfers in or out
- `closed` — permanent; requires zero balance before closing

### Idempotency
Every transfer requires a client-supplied `idempotency_key`, which must be a **UUID v4** generated by the client before the first attempt. The UUID format is enforced by `#[Assert\Uuid]` on `TransferCommand` — weak keys like `"retry"` or `"payment1"` are rejected at validation (400).

A duplicate key returns the original result — no second debit, ever. Backed by two safety nets:
1. **Application-level check** — `findByIdempotencyKey()` before executing (covers normal retries)
2. **Database `UNIQUE INDEX`** on `idempotency_key` — catches race conditions where two concurrent requests both pass the application check; the loser gets `UniqueConstraintViolationException`, re-fetches the winner's result, and returns it as 201

**Why UUID v4 specifically?** UUID v4 is random (122 bits of entropy), making accidental key collisions cryptographically improbable. Arbitrary short strings are dangerously collision-prone — two different operations could accidentally share a key like `"payment"` and one would silently return the other's result. Stripe, Wise, and Adyen all enforce UUID format for idempotency keys.

**Why client-generated?** The client must generate the key *before* the first attempt so it can reuse the same key on every retry. If the server generated the key, the client would not know it, making safe retries impossible.

**Failed transfers do not consume the idempotency key.** When a transfer fails (insufficient funds, currency mismatch, etc.), a `status: "failed"` Transfer row is persisted for audit purposes — but it is written with a **server-generated UUID**, not the client's key. This means the client can reuse the original key after fixing the problem (e.g. topping up the account) and the transfer will be re-attempted, not short-circuited by the idempotency guard.

### Optimistic Locking (not pessimistic)
The `Account` entity uses a `version` column (`@ORM\Version`). On `flush()`, Doctrine adds `WHERE version = :expected` to the UPDATE. If a concurrent request modified the same account, the WHERE matches nothing → `OptimisticLockException` → HTTP 409 with `Retry-After: 1`.

Pessimistic locking (`SELECT FOR UPDATE`) holds a row lock for the full transaction duration, killing throughput under concurrency. Optimistic locking never blocks reads — conflicts are rare and resolved by retry.

### Explicit ACID Transaction
The entire `handle()` call — idempotency check, account loading, debit/credit, and Transfer INSERT — runs inside `entityManager->wrapInTransaction()`. All three database writes commit atomically or roll back together.

### Transfer Limits & Rules

Every transfer is validated against two hard limits before any balance change occurs:

| Limit | Value | What it prevents |
|---|---|---|
| Single transfer max | €100,000.00 (10,000,000 cents) | One anomalously large transaction from a single request |
| Daily outgoing max | €500,000.00 (50,000,000 cents) | Rapid fund drainage from a compromised account within one day |

**Implementation details:**
- Checks run in `TransferCommandHandler` **before** `debit()` is called — fail fast, zero DB writes on violation
- The daily limit sums only `STATUS_COMPLETED` transfers: failed transfers never moved money, reversed transfers returned it, so neither counts against the cap
- The `sumDailyOutgoing()` query uses a DQL `SUM` aggregate — one query, no PHP-side iteration
- Violations dispatch `TransferFailedEvent` with reason `transfer_limit_exceeded` and flag the attempt as **high risk** in the fraud system
- The response includes `limit_type` (`single_transfer` or `daily_outgoing`) and `limit_amount` so clients can show a meaningful error message

**Limit exceeded response — 422:**
```json
{
  "error": "transfer_limit_exceeded",
  "message": "Transfer amount 100001.00 exceeds the single transfer limit of 100000.00.",
  "limit_type": "single_transfer",
  "limit_amount": 10000000
}
```

> **Production note:** The limits are currently constants in `TransferCommandHandler`. In production, inject them from `config/packages/transfer_limits.yaml` so the compliance team can adjust them without a code deploy. Account tiers (retail vs. business) would use different limit sets.

---

### Dependency Inversion
Application services depend on domain interfaces, not Doctrine:
```yaml
# services.yaml
App\Module\Account\Domain\AccountRepositoryInterface:
    alias: App\Module\Account\Infrastructure\Persistence\DoctrineAccountRepository
```
Swapping the persistence layer requires changing only this alias.

### Domain Events

After a transfer is committed to the database, `TransferCommandHandler` raises a `TransferCompletedEvent`. This decouples post-transfer side effects from the core business operation.

```
Transfer commits to DB
        ↓
TransferCompletedEvent dispatched
        ↓
TransferCompletedListener fires:
  ├── notifySender()     → email confirmation to sender (simulated via logger)
  └── updateFraudScore() → fraud detection system update (simulated via logger)
```

**Why events instead of direct calls from the handler:**
- The handler stays focused on one job (SRP) — it does not know about email or fraud systems
- Adding a new side effect (SMS, push notification, loyalty points) requires only a new listener class — the handler never changes (OCP)
- A broken email service does not roll back a completed transfer — listeners fail independently

**Event fires exactly once per unique transfer:**
Idempotency early-returns (duplicate requests) exit before the event dispatch line — the event was already raised when the original transfer was created.

**Production upgrade path (async notifications):**
Currently listeners run synchronously in the same request. For high load, add Symfony Messenger:
```bash
composer require symfony/messenger
```
Configure a Redis/RabbitMQ transport and the listeners automatically move to background workers — zero changes to `TransferCommandHandler` or the listeners required.

**Seven domain events are in use across both modules:**

**Transfer events:**

| Event | Dispatched when | Listener |
|---|---|---|
| `TransferCompletedEvent` | Transfer committed to DB | `TransferCompletedListener` — email confirmation, fraud score update |
| `TransferFailedEvent` | Business failure (insufficient funds, limit exceeded, etc.) | `TransferFailedListener` — failure notification, fraud flag |
| `TransferReversedEvent` | Reversal committed to DB | *(extend with `TransferReversedListener` as needed)* |

**Account lifecycle events:**

| Event | Dispatched when | Listener |
|---|---|---|
| `AccountCreatedEvent` | New account persisted | `AccountLifecycleListener` — welcome email, KYC pipeline trigger |
| `AccountSuspendedEvent` | Account status changes to `suspended` | `AccountLifecycleListener` — suspension notification, compliance team alert |
| `AccountActivatedEvent` | Account status changes from `suspended` → `active` | `AccountLifecycleListener` — reactivation notification, compliance case closure |
| `AccountClosedEvent` | Account permanently closed | `AccountLifecycleListener` — closure confirmation, compliance case archival |

**Idempotency-aware dispatch:** `AccountSuspendedEvent` and `AccountActivatedEvent` are only dispatched when the status *actually changes*. Re-suspending an already-suspended account or re-activating an already-active account is a no-op — no event fires, no duplicate notification is sent.

**One listener for all account events:** `AccountLifecycleListener` uses multiple `#[AsEventListener]` attributes pointing to different methods. All account lifecycle side effects are in one place, making the full account lifecycle easy to trace during an incident or code review.

**Relevant files:**
- `src/Module/Transfer/Domain/Event/` — Transfer events (immutable, carry primitive data only)
- `src/Module/Account/Domain/Event/` — Account lifecycle events
- `src/Shared/EventListener/TransferCompletedListener.php` — email + fraud score on transfer success
- `src/Shared/EventListener/TransferFailedListener.php` — notification + fraud flag on failure
- `src/Shared/EventListener/AccountLifecycleListener.php` — welcome email, KYC, compliance alerts

---

### Rate Limiting
Per-IP sliding window via Symfony's `RateLimiterFactory` (Redis-backed in production). Fires on `kernel.request` before any controller logic. Returns `429` with a `Retry-After` header indicating the exact number of seconds until the limit resets.

---

## Database Schema

```sql
accounts
  id          INT AUTO_INCREMENT PRIMARY KEY
  uuid        VARCHAR(36) NOT NULL UNIQUE  -- public-facing UUID v7 (used in all API responses and URLs)
  currency    VARCHAR(3) NOT NULL          -- ISO 4217
  status      VARCHAR(20) NOT NULL DEFAULT 'active'
  balance     BIGINT NOT NULL DEFAULT 0   -- minor units (cents)
  version     INT NOT NULL DEFAULT 1      -- optimistic lock
  created_at  DATETIME NOT NULL
  updated_at  DATETIME NOT NULL

transfers
  id                          INT AUTO_INCREMENT PRIMARY KEY
  uuid                        VARCHAR(36) NOT NULL UNIQUE   -- public-facing UUID v7
  idempotency_key             VARCHAR(64) NOT NULL UNIQUE   -- prevents double transfer
  from_account_id             INT NOT NULL  REFERENCES accounts(id)
  to_account_id               INT NOT NULL  REFERENCES accounts(id)
  amount                      BIGINT NOT NULL
  currency                    VARCHAR(3) NOT NULL
  status                      VARCHAR(20) NOT NULL          -- completed | failed
  failure_reason              VARCHAR(255)
  reversal_of_transfer_id     INT NULL                      -- internal FK to original transfer
  reversal_of_transfer_uuid   VARCHAR(36) NULL              -- UUID of original (exposed in API)
  created_at                  DATETIME NOT NULL

ledger_entries
  id             INT AUTO_INCREMENT PRIMARY KEY
  account_id     INT NOT NULL REFERENCES accounts(id)
  transfer_id    INT NOT NULL              -- plain int, no FK (cross-BC boundary)
  type           VARCHAR(10) NOT NULL      -- debit | credit
  amount         BIGINT NOT NULL           -- minor units (cents)
  currency       VARCHAR(3) NOT NULL
  balance_after  BIGINT NOT NULL           -- account balance snapshot after this entry
  created_at     DATETIME NOT NULL
```

Every successful transfer (and every reversal) produces exactly **two ledger entries**: a `debit` on the sender and a `credit` on the receiver. This is standard double-entry bookkeeping — the audit trail is append-only and can reconstruct the full balance history independently of the `accounts` table.

---

## Development Commands

```bash
make setup        # Full setup from scratch
make up           # Start containers
make down         # Stop containers
make migrate      # Run migrations (dev)
make migrate-test # Create test DB and run migrations
make fixtures     # Load seed data
make test         # Run integration tests
make shell        # Open shell in PHP container
make logs         # Tail container logs
```

---

## Development Notes

### Time Spent

| Phase | Activity | Time |
|---|---|---|
| Setup | Symfony project bootstrap, Docker compose, Nginx config, DB setup, Makefile | ~1 h |
| Domain | `Money` value object, `Account` + `Transfer` aggregate roots, domain exceptions, repository interfaces | ~1.5 h |
| Application | `TransferCommand`, `TransferCommandHandler` — idempotency, ACID transaction, optimistic locking | ~1.5 h |
| Infrastructure | Doctrine repositories, `services.yaml` DI wiring, `doctrine.yaml` entity path config | ~45 min |
| HTTP Layer | `TransferController`, `AccountController`, `ExceptionListener`, `RateLimitListener` | ~45 min |
| Tests | First round of integration tests — happy path, insufficient funds, idempotency, edge cases | ~1 h |
| Refactor | DDD modular monolith restructure — moved flat `src/` into `Account/`, `Transfer/`, `Shared/` modules | ~1 h |
| Domain Events | `TransferCompletedEvent`, `TransferFailedEvent`, `TransferReversedEvent`, listeners | ~45 min |
| Ledger System | `LedgerEntry`, double-entry bookkeeping, `GET /accounts/{id}/ledger`, migration | ~1.5 h |
| Transaction Filters | `TransferFilter` value object, dynamic query builder, filter params in controller | ~45 min |
| Reversal / Refund | `ReversalCommandHandler`, reversal idempotency, two-flush pattern in same transaction | ~1.5 h |
| Append-only fix | Identified `markAsReversed()` as a design mistake — removed mutation, added `reversedBy` derived at read time, batch N+1 fix | ~1 h |
| Limits & Rules | `TransferLimitExceededException`, `sumDailyOutgoing()`, per-transfer + daily cap | ~45 min |
| Account management | `POST /accounts`, `POST /accounts/{id}/suspend`, activate, close + lifecycle events | ~1 h |
| Pagination envelopes | `{ data, total, limit, offset }` on transfer history and ledger, `countByAccountId()` methods | ~30 min |
| Health endpoints | `GET /health` (liveness) + `GET /health/ready` (readiness + DB ping) | ~20 min |
| Documentation | `README.md` | ~1 h |
| DDD Layering Fix | Extracted `SuspendAccountCommand`, `ActivateAccountCommand`, `CloseAccountCommand` + handlers — controller no longer touches domain directly | ~45 min |
| UUID Identifiers | Dual-ID pattern: UUID v7 on `Account` + `Transfer`, all API routes and responses use UUID, migration `Version20260321000000` | ~1.5 h |
| Idempotency Key Validation | `#[Assert\Uuid]` on `TransferCommand.idempotencyKey` — reject weak keys, align with Stripe/Wise standards | ~20 min |
| Documentation update | `README.md` updated for UUID and idempotency key changes | ~20 min |
| **Total** | | **~20 hours** |

---

### Development Approach

I built this inside-out — domain layer first, HTTP layer last. The idea is that business rules shouldn't depend on Symfony or Doctrine at all; those are just delivery mechanisms.

**Build order:**

1. **Domain** — `Money`, `Account`, `Transfer`, repository interfaces, domain exceptions. No framework imports at this point — just PHP classes with business logic.

2. **Application** — `TransferCommand` + `TransferCommandHandler`. This is where the use case lives: idempotency check, ACID transaction, debit/credit delegation, optimistic locking.

3. **Infrastructure** — Doctrine repositories wired to domain interfaces via `services.yaml` aliases. The domain never references Doctrine directly.

4. **Cross-cutting** — `ExceptionListener` turns domain exceptions into JSON responses. `RateLimitListener` runs before controllers.

5. **HTTP layer** — thin controllers: parse request, build command, validate, call handler, return response. Zero business logic.

6. **Tests** — integration tests against a real MySQL instance. I deliberately avoided mocking the DB; past experience has taught me that mock/real divergence is where subtle bugs hide.

**Things I would do differently in production:**
- Inject transfer limits from config (not hardcoded constants) so compliance can change them without a deploy
- Move event listeners to async workers via Symfony Messenger under high load
- Add an ISO 4217 currency whitelist — right now any 3-letter string is accepted

---

## Out of Scope (production additions)

| Feature | Notes |
|---|---|
| Authentication / Authorization | API keys or OAuth2; caller must own `from_account` |
| ISO 4217 currency whitelist | Validate against an enum of real currency codes |
| Async processing | Symfony Messenger to move event listeners to background workers — zero handler changes required |
| Partial reversals | Current reversal always refunds the full amount; partial refund requires a `partialAmount` param in `ReversalCommand` |
| Metrics | Prometheus counters for transfer volume, latency, error rates |
| Read replicas | Separate read/write DB connections for account queries vs. transfer writes |
| Configurable limits | Move `MAX_SINGLE_TRANSFER` / `MAX_DAILY_OUTGOING` to YAML config; support per-account-tier limits |
