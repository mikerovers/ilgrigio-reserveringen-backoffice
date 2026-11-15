# Agent Guidelines

See `README.md` for local setup. This file is the authoritative guide for
coding agents working on this **Symfony 8.2** application (Dutch-language
ticketing storefront, EasyAdmin backoffice, door scanner and register/kassa).

---

## Commands

```bash
symfony server:start                     # dev server
composer install
./bin/phpunit                            # all tests
./bin/phpunit tests/Service/CheckoutServiceTest.php                       # one file
./bin/phpunit tests/Service/CheckoutServiceTest.php --filter testName     # one method
./bin/phpunit tests/Controller/          # one directory
./vendor/bin/phpcs                       # PSR-12 check (run before committing)
./vendor/bin/phpcbf                      # PSR-12 auto-fix
bin/console tailwind:build --watch       # required in dev (see Tailwind)
bin/console debug:router | debug:container | cache:clear | assets:install
```

---

## Directory Structure

```
src/
  Command/            # Console commands (app:user:create, app:orders:complete, ...)
  Controller/         # Storefront, API and dev-preview controllers
    Admin/            # EasyAdmin CRUD controllers and dashboard
    Register/         # Register (kassa) app, session based
    Scanner/          # Door scanner app
  DataFixtures/       # Doctrine fixtures (AppFixtures, PageFixtures, TicketTemplateFixtures)
  Doctrine/           # Custom Doctrine types (MoneyType)
  DTO/                # Request DTOs (validation) and *Response DTOs
  Entity/ Enum/       # Doctrine entities and PHP enums
  Event/              # Domain events (OrderCreatedEvent, OrderCompletedEvent, ...)
  EventListener/      # Listeners (OrderFulfilmentListener, OrderHistoryListener, TokenRefreshRateLimitListener)
  Exception/          # Domain exceptions
  Form/               # Symfony forms
  Message/ MessageHandler/   # Messenger messages and handlers
  Payment/            # PaymentInitiatorInterface, OmnipayMolliePaymentInitiator
  Repository/
  Security/           # Customer email-code authenticator
  Service/            # Business logic
  Twig/               # Twig extensions; Components/ holds Live Components
  Webhook/            # Mollie request parser and consumer
templates/            # Twig (HTML, email, PDF, register/, scanner/)
tests/                # PHPUnit, mirrors src/
translations/         # messages.nl.yaml
config/               # services.yaml (env bindings), packages/
```

---

## Core Workflows

### Checkout, payment, fulfilment
- The storefront builds a session cart, validates coupons, creates an order and
  starts a Mollie payment.
- `MollieRequestParser` only requires a POST with an `id` (it does **not** validate
  an HMAC). `MollieWebhookConsumer` re-fetches the authoritative payment from the
  Mollie API; that re-fetch is the verification boundary. Never trust the webhook
  body beyond the payment ID. It dispatches `PaymentStatusChangedEvent`, and when
  paid `OrderCompletedEvent`.
- A paid (or zero-total) order dispatches `OrderCompletedEvent`.
  `OrderFulfilmentListener` issues tickets idempotently, creates a signed PDF
  token and queues `SendOrderEmailMessage` on the `email` transport.
- `/bedankt` (`TicketingController::thankYou`) resolves the order strictly from the
  session (`order_number`). Never reintroduce a `?order=` lookup (it allowed
  enumerating other customers' orders).
- **Closed orders are final:** `MollieWebhookConsumer` ignores status changes for
  `cancelled`/`refunded` orders (it only records the payment status and an order
  note) and PDF tokens stop working for them.
- **Customers are immutable at checkout:** `CheckoutService::resolveCustomer` never
  changes an existing `Customer` (anyone can type any email). Staff views, search
  and order DTOs show the per-order `billing` name (falling back to the customer
  for legacy rows).
- `POST /events/{id}/scan` accepts JSON only (`acceptFormat: 'json'`) as its CSRF protection.

### Stock and overbooking
- Capacity lives on `SharedStock.stockQuantity`.
- `CheckoutService::createOrder` takes stock with the single atomic
  `SharedStockRepository::reserve()` UPDATE inside a DB transaction (pools locked in
  id order) and records a `StockReservation` per pool. Never reintroduce an
  in-memory check-then-decrement.
- Stock is returned exactly once via `StockReservationService::releaseForOrder()`
  (failed/canceled/expired Mollie payment, failed payment start, or
  `app:orders:expire-pending`). A late PAID re-takes it via `reacquireForOrder()`.
- Run `bin/console app:orders:expire-pending` every minute from cron;
  `ORDER_RESERVATION_TTL_MINUTES` (default 30) sets how long unpaid orders hold stock.
- Staff may overbook: `SharedStockRepository::take()` has no capacity guard and
  `Version20261115100000` dropped `chk_shared_stock_non_negative`, so pools can go
  below 0. Customer checkout still uses the guarded `reserve()`.

### Manual completion
`bin/console app:orders:complete <orderNumber>` marks a pending/processing/failed
order paid and completed (payment -> `PAID`, `Order::completeNow()`), re-takes
released stock for failed orders and dispatches `OrderCompletedEvent` (tickets,
PDF, confirmation email). Cancelled/refunded orders are refused; `--force` re-runs
fulfilment for an already completed order.

### Coupons
`DiscountType` is `percent` (basis points, 1000 = 10%), `fixed_cart` (cents off
the cart) or `by_ticket` (count of highest-priced ticket units made free;
`Coupon.amount` is that count). Register ad-hoc discounts stay `percent` or `fixed`;
`REGISTER_MAX_DISCOUNT_PERCENT` (default 100) caps a percent discount. Redemptions
on failed, cancelled or refunded orders do not count toward `usageLimit`.

### Terms & conditions
`/algemene-voorwaarden/` and the modal on every page are the `terms` `SitePage`
row, edited under Website pages (`heroTitle` = title, `heroDescription` = body,
plain text, blank lines start paragraphs). Seeded by `Version20261107120000` and
`PageFixtures`; the modal reads it via the `terms_page()` Twig function.

### Ticket PDF template (editable)
- Everything below the fixed header (event, QR, date, customer) in
  `templates/pdf/order.html.twig` comes from `TicketTemplate` > ordered
  `TicketTemplateSection`s, edited under Ticket templates
  (`TicketTemplateCrudController`: add/remove/reorder parts, each a Trix editor
  limited to bold and bullets).
- `Event::$ticketTemplate` picks one; empty uses the template flagged `isDefault`
  (at most one, enforced by a partial unique index); with no template the PDF has
  only the fixed header. The default is loaded by `TicketTemplateFixtures`, not a migration.
- `TicketTemplateResolver::sectionsForEvent()` returns sanitized HTML
  (`app.ticket_content` sanitizer: strong/b/ul/li/br/div, no attributes or links),
  applied on save and again at render.
- PDFs render on demand, so edits show on already-issued tickets. Dompdf runs with
  PHP and remote resources **disabled** (`SecurePdfStorageService`).

---

## Authentication and API

### Firewalls
- `main`: protects the EasyAdmin backoffice (`ADMIN_HOST`).
- `secure_api`: JWT for the register/check-in `/api` endpoints. Public storefront
  `/api` routes (coupon validation, available events) need no login. Any new
  JWT-only route must be added to the `secure_api` pattern in
  `config/packages/security.yaml`, otherwise it falls through to `main`, the JWT is
  ignored and the request redirects to the login form.
- `register`: session `form_login` for the kassa app (host-matched, see Register app).
- `customer`: passwordless customer login.
- `ROLE_ADMIN` inherits `ROLE_REGISTER` and `ROLE_CHECK_IN` (`role_hierarchy`), so
  admins can call every API endpoint. `CheckInController` requires `ROLE_CHECK_IN`,
  which `ROLE_REGISTER` does not grant.
- Create users with `bin/console app:user:create` (prompts for email, name, hidden
  password and roles; `--role` is repeatable and skips the role prompt, e.g.
  `--role=ROLE_CHECK_IN`). The old API-key authentication was removed.

### Register JWT API (`ROLE_REGISTER`)
- `GET /api/events` returns `ticketTypes` and `sharedStocks`; both carry
  `lowStockThreshold` and a derived `lowStock` flag (remaining <= threshold).
- `GET /api/event-categories`; `GET /api/me` (id, email, required name, roles; any
  authenticated JWT user).
- `GET /api/orders`: optional repeatable `status[]` filter
  (`pending|processing|completed|cancelled|failed|refunded`), optional `search`
  (case-insensitive, every word must match the order number or the customer's
  first/last name; `OrderListQuery`).
- `POST /api/orders` (register sale): items by ticket-type UUID; `paymentMethod`
  (only `cash`, default, completes immediately); optional coupon *or* ad-hoc
  `discount` {type `percent`|`fixed`, amount as plain percent (`10` = 10%, max 2
  decimals) or whole cents, reason}, stored internally as basis points; optional
  free-text `note` (max 1000, stored on `Order.note`, separate from the `OrderNote`
  history); optional `Idempotency-Key` header. Creates `Order.source = register`.
- `GET /api/orders/{orderNumber}` (same body as the create response);
  `GET .../tickets` (issued tickets in line-item order, empty before fulfilment);
  `GET|POST .../notes`; `POST .../process` (re-dispatches fulfilment, adds an order
  note naming the user).
- `DELETE /api/orders/{orderNumber}`: cancels (releases stock, voids tickets, frees
  the coupon, keeps the row as `cancelled`); idempotent; 409 for checked-in
  tickets; no refund is issued.
- `GET /api/sale-report` (tickets of completed orders, per year); `GET /api/coupons`
  (`search` on code); `PUT /api/coupons/{id}/deactivate` (only sets `active = false`,
  idempotent, no body).
- List endpoints paginate with `page`/`limit` (max 100) and order by `createdAt`
  via `orderDirection` (`PageQuery`, `CouponListQuery`, `EventListQuery`),
  returning `PaginatedResponse`.

### Customer login (behind `CUSTOMER_LOGIN_ENABLED`)
- `/inloggen` takes an email; `LoginCodeService` mails a 6-digit code
  (`SendLoginCodeMessage`, `email` transport). Only an HMAC of the code is stored in
  `LoginCode`; 10 min TTL, 5 attempts, single use, same response for unknown addresses.
- `EmailCodeAuthenticator` (`customer` firewall) verifies at `POST /inloggen/code`;
  `Customer` is the `ROLE_CUSTOMER` user.
- `/mijn-tickets` lists completed orders; `/mijn-tickets/{orderNumber}/pdf` streams
  the PDF only for the logged-in customer's own orders (404 otherwise).
- Limiters `login_code_request_ip|email` and `login_code_verify`. Purge old codes
  with `bin/console app:login-codes:purge` (cron). Dev mail preview:
  `/_dev/login-code/email-preview`. The test mailer is `null://null`.

### Rate limiting
Definitions live in `config/packages/rate_limiter.yaml`: `/api/login`
(`login_throttling` on the `login` firewall), `/api/validate-coupon`
(`limiter.validate_coupon`, enforced in `CartApiController`), `/api/token/refresh`
(`limiter.token_refresh`, enforced by `TokenRefreshRateLimitListener` on
`kernel.request` since gesdinet/jwt-refresh-token-bundle has no hook of its own).

### HTTP security headers
`nelmio/security-bundle` (`config/packages/nelmio_security.yaml`) sets
`X-Frame-Options`, `X-Content-Type-Options: nosniff` and `Referrer-Policy`. The CSP
is **report-only**, tuned to the CDNs/inline scripts still in use (Tailwind CDN on
the error pages, Font Awesome CDN, jsDelivr, `unsafe-inline`). Do not switch it to
`enforce` without first checking the report-only violations.

---

## Frontends

### Tailwind CSS (storefront + scanner + register)
- No Tailwind Play CDN (it compiled in the browser and left slow mobile pages
  unstyled). `assets/tailwind/app.css` is compiled by `symfonycasts/tailwind-bundle`
  (standalone CLI v3.4.17, no Node) using `tailwind.config.js`, linked via
  `asset('tailwind/app.css')` from `templates/base.html.twig` and
  `templates/scanner/base.html.twig`.
- Dev: run `bin/console tailwind:build --watch` (dev throws if it was never built).
  Deploy: `tailwind:build --minify` before `asset-map:compile` (already in `docker-entrypoint.sh`).
- Only the `content` paths in `tailwind.config.js` are scanned: a class built in a JS
  string must appear literally in source (no `${PREFIX}:text-sm`), and a class that
  only lives in database content (e.g. event `disabledReason` HTML) must go in `safelist`.
- Error pages in `templates/bundles/TwigBundle/Exception` deliberately keep the CDN
  so they never depend on the asset map.
- macOS may kill the downloaded binary (exit 137):
  `codesign --force --sign - var/tailwind/*/tailwindcss-*`. `Dockerfile.web`
  installs `gcompat libstdc++` because the v3 binary is glibc-only.

### Scanner app (`SCANNER_HOST`)
- Below Tailwind `md` (768px) the scan screen (`/`) is scan-only: compact header,
  stats, scan panel. The screen picker, recent-scans sidebar and order popup use
  `max-md:hidden` (kept in the DOM so Stimulus targets resolve).
- Input is the keyboard-wedge model (external Bluetooth/USB scanner types code +
  Enter); there is no camera scanning. On `(pointer: coarse)` devices
  `station_controller.js` sets `inputmode="none"` to hide the on-screen keyboard; a
  "Toetsenbord" button toggles it.
- `/scanners` (`ROLE_CHECK_IN`, F4) shows the Code 128 programming barcodes of the
  active `BarcodeScannerType`; types and payloads are edited in EasyAdmin under
  Scanbalie. Do not seed manufacturer command strings.

### Register (kassa) app (`REGISTER_HOST`)
- **Shell:** own host (`register_host` parameter), mirroring the scanner.
  Controllers in `src/Controller/Register/` bind `host: '%register_host%'`, routes
  named `register_*` (`index`, `login`, `logout`, `manifest`, `service_worker`,
  `orders`, `coupons`, `order_tickets`). The host-matched `register` firewall
  (session `form_login`, own `context`, login throttling) is declared before `main`;
  `access_control` needs `ROLE_REGISTER` except login, manifest and service worker.
- Because the firewall matches on host only, JWT `/api/*` endpoints are not reachable
  here: the sale UI must use session `register_*` controllers that call
  `RegisterOrderService`/`CheckoutService` directly.
- **Assets:** templates in `templates/register/` (`base`, `layout` with scanner-style
  masthead and tab menu, `login`, `index`, `sw.js.twig`); importmap entrypoint
  `register` (`assets/register/app.js`, Turbo); Stimulus controllers in
  `assets/controllers/register/` (`register--*`). Both paths are in `tailwind.config.js`.
- **Live Components** (`src/Twig/Components/`) answer on every host under
  `/_components`, so each must carry its own `#[IsGranted]`; the host-matched
  firewall does not protect them.
- **Kassa screen** (`register_index`, `/`): `RegisterEventPicker`. Left half =
  category select + name search + all upcoming shows of the category
  (`EventRepository::findForRegister`, no pagination, stock pools as pills) and a
  refresh button; selecting a show fills the right half, still a placeholder for the
  sale logic.
- **Orders** (`/bestellingen`): `RegisterOrderTable` (search LiveProp +
  `symfony/ux-pagination` via `ComponentWithPaginationTrait`, theme
  `register/pagination.html.twig`, fed by `OrderRepository::createListQueryBuilder`).
  Notes modal; cancel via `OrderCancellationService`; resend mail by dispatching
  `OrderCompletedEvent`.
  - "Download tickets" opens `register_order_tickets` (`/bestellingen/{n}/tickets`,
    new tab): standalone print sheet (order summary + one QR per valid ticket,
    `inline-block` cells with `break-inside: avoid`, 12 per A4 page) that calls
    `window.print()` on load. QR images come from
    `SecurePdfStorageService::qrDataUriForTicketCode`.
  - "Omboeken" opens a modal (`OrderRebookingService`): pick another upcoming show of
    the same category; ticket types auto-map by normalised name and can be changed.
    Tickets keep their codes and prices, snapshots move to the new show, stock is
    released and re-taken.
- **Coupons** (`/kortingscodes`): `RegisterCouponTable`, same pattern
  (`CouponRepository::createListQueryBuilder`); the row menu only offers "Ongeldig
  maken" (confirm modal, sets `active = false`, one-way like the API).
- Tests: `tests/Controller/Register/RegisterTest.php` (host from `REGISTER_HOST`,
  firewall name `register`).

### Dev-only previews
`when@dev` in `config/routes/dev_email_preview.yaml`:
`GET /_dev/orders/{orderNumber}/email-preview` and
`GET /_dev/tickets/{ticketCode}/preview`. Not registered outside `dev`.

---

## Code Style

### General
- **PHP 8.4+**: constructor property promotion, named arguments, `match`, enums, `readonly` where appropriate (not on Doctrine entities).
- **PSR-12** enforced by `phpcs.xml.dist`. Files are UTF-8, LF, 4-space indent, final newline (`.editorconfig`). `<?php` on its own line, no closing tag.

### Namespaces and imports
- `App\` maps to `src/`, `App\Tests\` to `tests/`.
- One `use` per line, alphabetical within groups: PHP core -> external packages -> Symfony -> App. No aliases unless there is a genuine name conflict.

### Classes and methods
- One class per file, filename equals class name. `final` preferred for services and messages without inheritance need.
- Constructor promotion for dependencies; an empty body is `{}` on the same line as `)`:
  ```php
  public function __construct(
      private LoggerInterface $logger,
      private string $tokenSecret,
      private int $tokenExpirationDays = 150
  ) {}
  ```
- Explicit visibility; public methods first, then `private`/`protected` helpers. Keep methods focused.

### Naming
Classes `PascalCase`; methods/variables `camelCase`; constants `UPPER_SNAKE_CASE`;
route names `snake_case`; templates `snake_case.html.twig`; translation keys
dot-separated lowercase (`email.customer.subject`).

### Types
Always declare parameter, return and property types. Prefer specific types over
`mixed`. Use `?Type`, not `Type|null`, for nullable returns. Annotate complex array
shapes in docblocks.

### Symfony patterns
- Autowired and autoconfigured services; no manual service ID strings. Scalar config is bound in `services.yaml` `arguments:` via `%env(VAR)%`.
- `#[Route]` on actions; `#[AsRemoteEventConsumer('name')]` for webhook consumers; `#[AsMessageHandler]` on handlers.
- `#[AsMessage('email')]` (or `async`) on messages so they match `messenger.yaml` routing (`SendOrderEmailMessage` is `email`).
- `#[Assert\...]` on DTOs. Controllers throw `$this->createNotFoundException(...)` and re-throw domain exceptions.

### API response DTOs
- Successful responses are `*Response` DTOs from `src/DTO`, returned with `$this->json($dto)`. Never expose Doctrine entities or an ad-hoc success array. Error bodies may stay `['message' => ...]`.
- Each DTO has a static factory (`fromEntity()` / `fromEntities()`); keep mapping out of controllers.
- Paginated lists use `PaginatedResponse`; wrap a nested collection in its own DTO (e.g. `OrderTicketsResponse`, `OrderDetailResponse::$lineItems`), not a bare array.
- Document the DTO in the route's OpenAPI attribute with `new Model(type: SomeResponse::class)`; mark dates `#[OA\Property(..., format: 'date-time')]` as ISO 8601 with an explicit UTC offset.

### Messenger queues
- `async`, `email` and `failed` transports share the `messenger_messages` table, distinguished by `queue_name`.
- Order mail is the `email` transport. Run `bin/console messenger:consume email -vv` (add `async` if used). Local `.env` sets `MESSENGER_EMAIL_TRANSPORT_DSN=sync://` (inline sending); a Doctrine DSN queues instead.
- Failures retry five times with exponential backoff (5 min start, 1 h cap), then move to `failed`. Successful messages are removed.
- Inspect with `messenger:stats`, `messenger:failed:show|retry|remove`.
- Keep `auto_setup: false` in production; change the schema with Doctrine migrations.

### Dates and timezones
- The kernel forces PHP's default timezone to `UTC` (`Kernel::boot`). The database (`timestamp without time zone`) and API hold UTC instants; API values are ISO 8601 with `+00:00`. Never persist a `DateTimeImmutable` that is not UTC (Doctrine drops the timezone).
- The venue is in Amsterdam: Twig's `date` filter renders `Europe/Amsterdam` (`config/packages/twig.yaml`); EasyAdmin date fields use `Controller\Admin\VenueDateTimeField`; PHP code formatting a stored instant for people or parsing typed local time must use `App\Service\VenueTime` (`toLocal` / `fromLocal`).

### Error handling and logging
- Logs reach New Relic via the PHP APM agent's log forwarding (`docker/newrelic.ini`). Do not add a Monolog handler that makes HTTP calls in the request path.
- Catch `\Exception` at integration boundaries, log, then re-throw; never swallow silently.
- Log with context arrays: `$this->logger->error('Descriptive message', ['key' => $value]);`. Use `info` for success milestones.
- Truncate sensitive values in logs (token: first 8 chars + `...`).

### Security
- HMAC-SHA256 (`hash_hmac`) with `hash_equals()` for every signature/token comparison.
- Stateless signed tokens, no token storage. Secrets come from environment variables, never hardcoded.

### Testing
- Tests mirror `src/` under `tests/`. Unit tests extend `PHPUnit\Framework\TestCase`; functional tests extend `WebTestCase`. PHPUnit **11.5** (`phpunit.dist.xml`).
- Names: `testSomeDescriptiveBehaviourInCamelCase(): void`; `setUp()` builds mocks and the system under test.
- `$this->createMock(Interface::class)`; intersection type `MockObject|Interface $dep`. In functional tests swap services with `static::getContainer()->set(Class::class, $mock)`.
- Assert specific status codes, content types and body content in controller tests.
- Functional tests use a real Postgres DB. `phpunit.dist.xml` points at port 5432; if yours differs (e.g. docker on 32768) export `DATABASE_URL` for a separate test database and run `APP_ENV=test bin/console doctrine:migrations:migrate -n` once.
- JWT API tests (see `tests/Controller/RegisterApiTest.php`) create a user with the needed role, log in via `/api/login` and send `Authorization: Bearer <token>`.

---

## Environment Variables

Configured through `.env`, `.env.dev`, `.env.test`.

| Variable | Description |
|---|---|
| `APP_ENV` / `APP_SECRET` | Symfony environment (`dev`, `prod`, `test`) / secret |
| `DATABASE_URL` | Doctrine DSN |
| `CORS_ALLOW_ORIGIN` | Allowed CORS origins for API endpoints |
| `MAILER_DSN` | SMTP/SES DSN for Symfony Mailer |
| `MAILER_FROM_EMAIL` / `MAILER_FROM_NAME` | Sender address (falls back to the configured default) / display name |
| `FROM_EMAIL` | Legacy `app.from_email` parameter; keep aligned with `MAILER_FROM_EMAIL` |
| `ADMIN_EMAIL` | Admin notification address |
| `ADMIN_HOST` | Bare backoffice host (no port), e.g. `admin.reserveringen.ilgrigio.nl`, `admin.localhost` |
| `SCANNER_HOST` | Bare scanner host, e.g. `scan.ilgrigio.nl`, `scanner.localhost` |
| `REGISTER_HOST` | Bare kassa host, e.g. `kassa.ilgrigio.nl`, `kassa.localhost` |
| `TRUSTED_HOSTS` | Prod only (`framework.trusted_hosts`): one regex covering every host, e.g. `^(reserveringen\.ilgrigio\.nl\|admin\.reserveringen\.ilgrigio\.nl\|scan\.ilgrigio\.nl\|kassa\.ilgrigio\.nl)$`. Must be set in prod or the kernel fails to boot |
| `SECURE_CHANNEL` | `https` in production to require HTTPS for non-API routes |
| `TRUSTED_PROXIES` | IPs/CIDRs of the reverse proxy allowed to set `X-Forwarded-*`. Local default `127.0.0.1,::1`. Never `0.0.0.0/0` in prod |
| `JWT_SECRET_KEY` / `JWT_PUBLIC_KEY` / `JWT_PASSPHRASE` | JWT key paths and passphrase |
| `ILGRIGIO_RESERVERINGEN_BASE_URL` | Base URL used in email links |
| `PDF_TOKEN_SECRET` / `PDF_TOKEN_EXPIRATION_DAYS` | HMAC secret for PDF download tokens / validity (default 150) |
| `MOLLIE_API_KEY` | Mollie API key |
| `MAX_TICKETS_PER_ORDER` | Ticket limit per order |
| `TAX_RATE` | VAT rate, e.g. `9` |
| `CUSTOMER_LOGIN_ENABLED` | `1` enables `/inloggen` and `/mijn-tickets`; `0` (default) makes them 404 |
| `REGISTER_MAX_DISCOUNT_PERCENT` | Cap on register ad-hoc percent discounts (default `100`) |
| `REGISTER_GROUP_DISCOUNT_PERCENT` | Percentage of the kassa "Groepskorting" button (default `10`) |
| `ORDER_RESERVATION_TTL_MINUTES` | How long an unpaid web order holds stock (default `30`) |
| `MESSENGER_TRANSPORT_DSN` | `async` queue, e.g. `doctrine://default?queue_name=async` |
| `MESSENGER_EMAIL_TRANSPORT_DSN` | `email` transport: `sync://` sends inline, a Doctrine DSN queues |
| `MESSENGER_FAILED_TRANSPORT_DSN` | Exhausted messages, e.g. `doctrine://default?queue_name=failed` |
| `NEW_RELIC_LICENSE_KEY` | Read by the PHP APM agent in `Dockerfile.web`/`Dockerfile.messenger` (empty = idle) |
| `NEW_RELIC_APP_NAME` | Application name (distinct name for the messenger container) |
| `NEW_RELIC_LOG_LEVEL` | Minimum level forwarded to New Relic Logs (`DEBUG`..`EMERGENCY`, default `WARNING`) |
| `LOCK_DSN` | Symfony Lock store backing the rate limiters (e.g. `flock`) |
