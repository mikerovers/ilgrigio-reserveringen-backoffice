# Agent Guidelines

**See `README.md` for local setup.** This file is the authoritative guide for
the current codebase; the short `CLAUDE.md` only points back here.

This document provides comprehensive technical prescriptions for coding agents working on this **Symfony 7.4** application.

---

## Build, Lint & Test Commands

### Development server
```bash
symfony server:start
```

### Install dependencies
```bash
composer install
```

### Run all tests
```bash
./bin/phpunit
```

### Run a single test file
```bash
./bin/phpunit tests/Service/CheckoutServiceTest.php
```

### Run a single test method
```bash
./bin/phpunit tests/Service/CheckoutServiceTest.php --filter testPercentCouponIsApplied
```

### Run a test suite by directory
```bash
./bin/phpunit tests/Controller/
```

### Code style check (PSR-12)
```bash
./vendor/bin/phpcs
```

### Code style auto-fix
```bash
./vendor/bin/phpcbf
```

### Symfony console commands
```bash
bin/console cache:clear
bin/console debug:router
bin/console debug:container
bin/console assets:install
```

---

## Directory Structure

```
src/
  Command/            # Symfony console commands (CreateUserCommand, etc.)
  Controller/         # HTTP controllers
    Admin/            # EasyAdmin CRUD controllers and dashboard
  DataFixtures/       # Doctrine fixtures (AppFixtures)
  Doctrine/           # Custom Doctrine types (MoneyType)
  DTO/                # Data Transfer Objects with validation constraints
  Entity/             # Doctrine entities (Order, Ticket, Event, Customer, Payment, etc.)
  Enum/               # PHP enums (OrderStatus, TicketStatus, PaymentStatus, etc.)
  Event/              # Domain events (OrderCreatedEvent, OrderCompletedEvent, etc.)
  EventListener/      # Symfony event listeners (OrderFulfilmentListener, OrderHistoryListener, TokenRefreshRateLimitListener)
  Exception/          # Domain exceptions (CouponUnavailableException, RegisterOrderException)
  Message/            # Symfony Messenger message classes
  MessageHandler/     # Async message handlers
  Payment/            # Payment integration (OmnipayMolliePaymentInitiator, PaymentInitiatorInterface)
  Repository/         # Doctrine repositories
  Security/           # API key authenticator and user provider
  Service/            # Business logic services
  Webhook/            # Mollie webhook request parser and consumer
templates/            # Twig templates (HTML, email, PDF)
tests/                # PHPUnit tests mirroring src/ structure
translations/         # i18n YAML files (currently Dutch: messages.nl.yaml)
config/
  services.yaml       # Service definitions and environment variable bindings
  packages/           # Per-package Symfony configuration
```

## Core Workflows

- The public ticketing flow builds a session cart, validates coupons, creates an
  order, and starts a Mollie payment.
- `MollieRequestParser` accepts the payment ID from Mollie's POST webhook;
  `MollieWebhookConsumer` fetches the authoritative payment status from the
  Mollie API before updating the order.
- A paid (or zero-total) order dispatches `OrderCompletedEvent`.
  `OrderFulfilmentListener` issues tickets idempotently, creates a signed PDF
  token, and queues `SendOrderEmailMessage` on the Doctrine-backed `email`
  transport.
- The `main` firewall protects the EasyAdmin backoffice. Most `/api` endpoints
  use the API-key authenticator; check-in and order reprocessing require a JWT.
- **Register (JWT, `ROLE_REGISTER`) API:** `GET /api/events` (returns both
  `ticketTypes` and `sharedStocks`; stock pools and ticket types carry `lowStockThreshold`
  and a derived `lowStock` flag, true when remaining quantity <= threshold), `GET /api/event-categories`,
  `GET /api/me` (current user: id, email, required name, roles; any authenticated JWT user),
  `GET /api/orders` (optional `search`: case-insensitive, every word must match the order
  number or the customer's first/last name; `OrderListQuery`), `POST /api/orders` (register sale: items by ticket-type UUID,
  `paymentMethod` (only `cash` for now, default; the order completes immediately), optional coupon *or* ad-hoc
  `discount` {type percent|fixed, amount as plain percent (`10` = 10%, max 2 decimals) | whole cents, reason}; stored internally as basis points, optional
  free-text `note` (max 1000, stored on `Order.note`, separate from the `OrderNote` history), optional
  `Idempotency-Key` header; creates `Order.source = register`),
  `GET /api/orders/{orderNumber}` (same detail body as the create response),
  `DELETE /api/orders/{orderNumber}` (cancels a *register* order: releases stock, voids
  tickets, frees the coupon, keeps the row as `cancelled`; idempotent; 409 for web/Mollie
  orders or checked-in tickets; no refund is issued),
  `GET|POST /api/orders/{orderNumber}/notes`, `POST /api/orders/{orderNumber}/process`
  (re-dispatches fulfilment and adds an order note naming the user),
  `GET /api/sale-report` (tickets of completed orders, per year), `GET /api/coupons` (`search` on code) and `PUT /api/coupons/{id}/deactivate` (only sets
  `active = false`, idempotent, no body). List endpoints are paginated with
  `page`/`limit` (max 100) and order by `createdAt` via `orderDirection`
  (`PageQuery`, `CouponListQuery`; events use `EventListQuery`), returning
  `PaginatedResponse`. Any new JWT-only route must also be added to the
  `secure_api` firewall pattern in `config/packages/security.yaml`, otherwise it
  falls through to the API-key firewall and a JWT gets a 401. `ROLE_ADMIN` inherits
  `ROLE_REGISTER` via `role_hierarchy`, so admins can call every API endpoint;
  create dedicated register users with
  `app:user:create <email> <password> --role=ROLE_REGISTER --name="Display Name"` (`--name` is required).
- **Stock / overbooking:** capacity lives on `SharedStock.stockQuantity`.
- **Manual completion:** `bin/console app:orders:complete <orderNumber>` marks a
  pending/processing/failed order paid and completed (payment -> `PAID`,
  `Order::completeNow()`), re-takes released stock for failed orders and
  dispatches `OrderCompletedEvent` so tickets, PDF and the confirmation email
  are issued. Cancelled/refunded orders are refused; `--force` re-runs
  fulfilment for an already completed order.
  `CheckoutService::createOrder` takes stock with the single atomic
  `SharedStockRepository::reserve()` UPDATE inside a DB transaction (pools
  locked in id order) and records a `StockReservation` per pool. Never
  reintroduce an in-memory check-then-decrement. Stock is returned exactly once
  via `StockReservationService::releaseForOrder()` (failed/canceled/expired
  Mollie payment, failed payment start, or `app:orders:expire-pending`). A late
  PAID re-takes it via `reacquireForOrder()`. Run
  `bin/console app:orders:expire-pending` every minute from cron;
  `ORDER_RESERVATION_TTL_MINUTES` (default 30) sets how long unpaid orders hold stock.
- **Coupons:** `DiscountType` is `percent` (basis points, 1000 = 10%),
  `fixed_cart` (cents off the cart), or `by_ticket` (count of highest-priced
  ticket units made free; `Coupon.amount` is that count). Register ad-hoc
  discounts stay `percent` or `fixed` only; `REGISTER_MAX_DISCOUNT_PERCENT`
  (default 100) caps a percent discount. Redemptions on failed, cancelled, or
  refunded orders do not count toward `usageLimit`.
- **Dev-only previews** (`when@dev` in `config/routes/dev_email_preview.yaml`):
  `GET /_dev/orders/{orderNumber}/email-preview` and
  `GET /_dev/tickets/{ticketCode}/preview`. They are not registered outside `dev`.
- `/bedankt` (`TicketingController::thankYou`) resolves the order strictly from
  the session (`order_number`), never from a query parameter — do not
  reintroduce a `?order=`-driven lookup, as that previously allowed anyone to
  view another customer's order by guessing/enumerating an order number.
- Rate limiting guards the public/auth API surface: `/api/login`
  (`login_throttling` on the `login` firewall), `/api/validate-coupon`
  (`limiter.validate_coupon`, enforced in `CartApiController`), and
  `/api/token/refresh` (`limiter.token_refresh`, enforced by
  `TokenRefreshRateLimitListener` on `kernel.request` since
  gesdinet/jwt-refresh-token-bundle has no controller/hook of its own to
  attach a limiter to). Limiter definitions live in
  `config/packages/rate_limiter.yaml`.
- `nelmio/security-bundle` (`config/packages/nelmio_security.yaml`) sets
  baseline HTTP security headers (clickjacking/`X-Frame-Options`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy`). The CSP is in
  **report-only** mode, tuned to the CDNs/inline scripts the storefront and
  EasyAdmin login page currently rely on (Tailwind CDN, Font Awesome CDN,
  jsDelivr, `unsafe-inline`) — do not flip it to `enforce` without first
  checking `Content-Security-Policy-Report-Only` violation reports.

---

## Code Style Guidelines

### General
- **PHP 8.2+** — use modern syntax: constructor property promotion, named arguments, `match`, enums, `readonly` where appropriate
- **PSR-12** strictly enforced via PHP_CodeSniffer (`phpcs.xml.dist`). Always run `./vendor/bin/phpcs` before committing.
- Files use UTF-8, LF line endings, 4-space indentation, final newline (see `.editorconfig`)
- Opening `<?php` tag on its own line; no closing `?>` tag

### Namespaces and imports
- Root namespace: `App\` (maps to `src/`), test namespace: `App\Tests\`
- One `use` statement per line; alphabetical ordering within groups
- Group: PHP core/standard library → external packages → Symfony → App classes
- Do not use `use` aliases unless there is a genuine naming conflict

### Classes and methods
- One class per file; filename must match class name exactly
- `final` classes preferred for services and message classes when there is no inheritance need
- Constructor property promotion for injected dependencies:
  ```php
  public function __construct(
      private LoggerInterface $logger,
      private string $tokenSecret,
      private int $tokenExpirationDays = 150
  ) {}
  ```
- Short-form empty constructor body `{}` on the same line as the closing `)` when the body is empty
- Method visibility always explicit (`public`, `protected`, `private`)
- Public API methods first, then `private`/`protected` helpers
- Keep methods focused; extract private helpers for complex sub-tasks

### Naming conventions
- Classes: `PascalCase` (e.g., `SecurePdfStorageService`, `SendOrderEmailMessage`)
- Methods and variables: `camelCase`
- Constants: `UPPER_SNAKE_CASE`
- Symfony route names: `snake_case` (e.g., `pdf_download`, `ticketing_index`)
- Template files: `snake_case.html.twig`
- Translation keys: dot-separated lowercase (e.g., `email.customer.subject`)

### Type declarations
- **Always** declare parameter types, return types, and property types
- Prefer specific types over `mixed`; use union types when necessary
- Return `?Type` (nullable) rather than `Type|null` for nullable return types
- Use `array` for untyped arrays; annotate shape with docblocks if complex

### Symfony-specific patterns
- Services are autowired and autoconfigured — no manual service ID strings in code
- Services accept scalar config values via `services.yaml` `arguments:` bound to `%env(VAR)%`
- Use `#[Route(...)]` attribute on controller actions
- Use `#[AsRemoteEventConsumer('name')]` for webhook consumers implementing `ConsumerInterface`
- Use `#[AsMessageHandler]` on message handler classes
- Use `#[AsMessage('email')]` (or `async`) on message classes so they match `messenger.yaml` routing. `SendOrderEmailMessage` is `email`.
- Use Symfony Validator `#[Assert\...]` attributes on DTOs for input validation
- Throw `$this->createNotFoundException(...)` from controllers; re-throw domain exceptions

### Messenger queues

- The `async`, `email`, and `failed` Messenger transports share the database-backed
  `messenger_messages` table; distinguish them by `queue_name`.
- Order confirmation mail is the `email` transport (`SendOrderEmailMessage`).
  Run `bin/console messenger:consume email -vv` (add `async` if something is
  routed there). Local `.env` sets `MESSENGER_EMAIL_TRANSPORT_DSN=sync://`, so
  mail is sent in-process unless that DSN is a Doctrine queue.
- The worker retries operational failures five times
  with exponential backoff (starting at five minutes and capped at one hour),
  then moves the message to `failed`.
- Successful messages are acknowledged and removed. Inspect queued counts with
  `messenger:stats`, and inspect or recover exhausted messages with
  `messenger:failed:show`, `messenger:failed:retry`, and
  `messenger:failed:remove`.
- Keep `auto_setup: false` in production configuration; create or alter the
  transport schema with Doctrine migrations instead of runtime DDL.

### Webhook ingress

- **Mollie payments**: `MollieRequestParser` accepts the payment ID, and
  `MollieWebhookConsumer` retrieves the payment's authoritative state using the
  Mollie API. It dispatches `PaymentStatusChangedEvent`; when paid, it then
  dispatches `OrderCompletedEvent`, which triggers fulfilment.
- Do not describe the parser as validating an HMAC signature: it currently only
  requires a POST request with an `id`. The API re-fetch in the consumer is the
  payment-status verification boundary.

### Dates and timezones
- The kernel forces PHP's default timezone to `UTC` (`Kernel::boot`); the database
  (`timestamp without time zone`) and the API hold UTC instants, API values are ISO 8601 with `+00:00`.
- The venue is in Amsterdam: Twig's `date` filter renders `Europe/Amsterdam`
  (`config/packages/twig.yaml`), EasyAdmin date fields use `Controller\Admin\VenueDateTimeField`
  (edited in Amsterdam time, stored as UTC), and PHP code that formats a stored instant for people or
  parses a typed local time must go through `App\Service\VenueTime` (`toLocal` / `fromLocal`).
  Never persist a `DateTimeImmutable` that is not in UTC: Doctrine drops the timezone.

### Error handling and logging
- Catch `\Exception` at integration boundaries; re-throw after logging
- Always log errors with contextual data arrays (order ID, token prefix, etc.):
  ```php
  $this->logger->error('Descriptive message', ['key' => $value]);
  ```
- Never swallow exceptions silently
- Use `$this->logger->info(...)` for successful operation milestones
- Truncate sensitive values in logs (e.g., token: first 8 chars + `...`)
- Use `hash_equals()` for all cryptographic comparisons (timing-safe)

### Security
- HMAC-SHA256 (`hash_hmac`) with `hash_equals` for signature/token verification
- No token storage — stateless signed token system
- Secrets always from environment variables, never hardcoded
- Mollie webhook consumers must retrieve payment status from Mollie's API; do
  not trust the inbound webhook body beyond its payment ID

### Testing
- Test classes mirror `src/` structure under `tests/`
- Unit tests extend `PHPUnit\Framework\TestCase`; functional/integration tests extend `Symfony\Bundle\FrameworkBundle\Test\WebTestCase`
- Test method names: `testSomeDescriptiveBehaviourInCamelCase(): void`
- `setUp()` initialises mocks and the system under test
- Use `$this->createMock(InterfaceName::class)` for dependencies
- Mock intersection types: `MockObject|InterfaceName $dependency`
- Assert specific HTTP status codes, content types, and body content in controller tests
- Replace container services with mocks via `static::getContainer()->set(ClassName::class, $mock)` in functional tests
- PHPUnit version: **11.5** (configured in `phpunit.dist.xml`, which PHPUnit 11 loads when `phpunit.xml` is absent)
- Functional tests use a real Postgres database. `phpunit.dist.xml` points at
  port 5432; if your local DB runs elsewhere (e.g. the docker container on
  32768), export `DATABASE_URL` for a separate test database and run
  `APP_ENV=test bin/console doctrine:migrations:migrate -n` once. JWT API tests
  (see `tests/Controller/RegisterApiTest.php`) create users with the needed
  role, log in via `/api/login`, and send `Authorization: Bearer <token>`.

---

## Environment Variables

All environment configuration is injected via `.env` files (`.env`, `.env.dev`, `.env.test`). Required variables for full functionality:

| Variable | Description |
|---|---|
| `APP_ENV` | Symfony environment (`dev`, `prod`, `test`) |
| `APP_SECRET` | Symfony application secret |
| `DATABASE_URL` | Doctrine database connection DSN |
| `CORS_ALLOW_ORIGIN` | Allowed CORS origins for API endpoints |
| `MAILER_DSN` | SMTP/SES DSN for Symfony Mailer |
| `MAILER_FROM_EMAIL` | Sender email address (falls back to the configured default) |
| `MAILER_FROM_NAME` | Sender display name |
| `ADMIN_EMAIL` | Admin notification address |
| `FROM_EMAIL` | Legacy application parameter for sender email; keep aligned if code uses `app.from_email` |
| `ADMIN_HOST` | Hostname the backoffice UI (EasyAdminBundle) is bound to, e.g. `admin.reserveringen.ilgrigio.nl` in prod, `admin.localhost:8000` locally |
| `SECURE_CHANNEL` | Set to `https` in production to require HTTPS for non-API routes |
| `JWT_SECRET_KEY` | Path to JWT private key |
| `JWT_PUBLIC_KEY` | Path to JWT public key |
| `JWT_PASSPHRASE` | Passphrase for JWT key pair |
| `ILGRIGIO_RESERVERINGEN_BASE_URL` | Base URL for this application (used in email links) |
| `PDF_TOKEN_SECRET` | Secret for HMAC-signed PDF download tokens |
| `PDF_TOKEN_EXPIRATION_DAYS` | Token validity in days (default 150) |
| `MOLLIE_API_KEY` | Mollie payment API key |
| `MAX_TICKETS_PER_ORDER` | Ticket purchase limit per order |
| `REGISTER_MAX_DISCOUNT_PERCENT` | Cap on register ad-hoc percent discounts (default `100`) |
| `ORDER_RESERVATION_TTL_MINUTES` | How long an unpaid web order holds stock (default `30`) |
| `TAX_RATE` | VAT rate (e.g. `9` for 9%) |
| `API_KEY` | API key for `OrderApiController` authentication |
| `MESSENGER_TRANSPORT_DSN` | Doctrine DSN for the `async` queue, e.g. `doctrine://default?queue_name=async` |
| `MESSENGER_EMAIL_TRANSPORT_DSN` | DSN for order emails (`email` transport). `sync://` sends inline; a Doctrine DSN queues them |
| `MESSENGER_FAILED_TRANSPORT_DSN` | Doctrine DSN for exhausted messages, e.g. `doctrine://default?queue_name=failed` |
| `NEW_RELIC_LICENSE_KEY` | New Relic license key for APM/logging |
| `NEW_RELIC_APP_NAME` | New Relic application name |
| `NEW_RELIC_LOGS_ENDPOINT` | New Relic logs API endpoint |
| `LOCK_DSN` | Symfony Lock store DSN backing the rate limiters (e.g. `flock`) |
