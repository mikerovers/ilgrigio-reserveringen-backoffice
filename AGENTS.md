# Agent Guidelines

**See CLAUDE.md for project overview, architecture, and key workflows.**

This document provides comprehensive technical prescriptions for coding agents working on this **Symfony 7.3** application.

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
./bin/phpunit tests/Service/WebhookSecurityServiceTest.php
```

### Run a single test method
```bash
./bin/phpunit tests/Service/WebhookSecurityServiceTest.php --filter testValidateWooCommerceSignatureWithValidSignature
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
  ArgumentResolver/   # Custom argument resolvers for DTOs
  Command/            # Symfony console commands
  Controller/         # HTTP controllers (webhook consumer, PDF download, ticketing)
  DTO/                # Data Transfer Objects with validation constraints
  Message/            # Symfony Messenger message classes
  MessageHandler/     # Async message handlers
  Messenger/          # Custom Messenger transport serializers
  Security/           # API key authenticator and user provider
  Service/            # Business logic services
  Webhook/            # WooCommerce webhook request parser + order normalizer
templates/            # Twig templates (HTML, email, PDF)
tests/                # PHPUnit tests mirroring src/ structure
translations/         # i18n YAML files (currently Dutch: messages.nl.yaml)
config/
  services.yaml       # Service definitions and environment variable bindings
  packages/           # Per-package Symfony configuration
```

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
- Use `#[AsMessage('async')]` on message classes
- Use Symfony Validator `#[Assert\...]` attributes on DTOs for input validation
- Throw `$this->createNotFoundException(...)` from controllers; re-throw domain exceptions

### WooCommerce webhook ingress (two interchangeable routes)

Completed orders reach the app via **one of two** ingress routes, configured as the
WooCommerce Delivery URL (only one active at a time — see
`docs/aws-webhook-ingress.md`):

- **Route A — API Gateway → SQS** (resilient; app web service off the delivery
  path): raw webhook lands on the `webhook_ingest` queue, decoded by
  `App\Messenger\WooCommerceIngestSerializer`, handled by
  `IncomingWooCommerceWebhookMessageHandler` (validate signature → normalize →
  `processOrder()`).
- **Route B — HTTP `/webhook`** (original): `WooCommerceRequestParser` +
  `WooCommerceWebhookController` (`#[AsRemoteEventConsumer('woocommerce')]`).

Both routes share `WooCommerceOrderNormalizer` (legacy vs. `{action, arg}` payload
formats) and converge on `OrderPdfService::processOrder()`. When adding ingress
logic, change the shared normalizer/service — not one route only.

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
- Webhook signature validated in `WooCommerceRequestParser` before payload reaches controller

### Testing
- Test classes mirror `src/` structure under `tests/`
- Unit tests extend `PHPUnit\Framework\TestCase`; functional/integration tests extend `Symfony\Bundle\FrameworkBundle\Test\WebTestCase`
- Test method names: `testSomeDescriptiveBehaviourInCamelCase(): void`
- `setUp()` initialises mocks and the system under test
- Use `$this->createMock(InterfaceName::class)` for dependencies
- Mock intersection types: `MockObject|InterfaceName $dependency`
- Assert specific HTTP status codes, content types, and body content in controller tests
- Replace container services with mocks via `static::getContainer()->set(ClassName::class, $mock)` in functional tests
- PHPUnit version: **11.5** (configured in `phpunit.xml.dist`)

---

## Environment Variables

All environment configuration is injected via `.env` files (`.env`, `.env.dev`, `.env.test`). Required variables for full functionality:

| Variable | Description |
|---|---|
| `APP_SECRET` | Symfony application secret |
| `MAILER_DSN` | SMTP/SES DSN for Symfony Mailer |
| `MAILER_FROM_EMAIL` | Sender email address |
| `MAILER_FROM_NAME` | Sender display name |
| `ADMIN_EMAIL` | Admin notification address |
| `WOOCOMMERCE_CONSUMER_KEY` | WooCommerce API key |
| `WOOCOMMERCE_CONSUMER_SECRET` | WooCommerce API secret |
| `WOOCOMMERCE_WEBHOOK_SECRET` | HMAC secret for webhook signature validation |
| `ILGRIGIO_BASE_URL` | Base URL of the WooCommerce site |
| `ILGRIGIO_BASE_API_URL` | WooCommerce REST API root URL |
| `ILGRIGIO_RESERVERINGEN_BASE_URL` | Base URL for this application (used in email links) |
| `PDF_TOKEN_SECRET` | Secret for HMAC-signed PDF download tokens |
| `PDF_TOKEN_EXPIRATION_DAYS` | Token validity in days (default 150) |
| `ILGRIGIO_TICKET_API_URL` | Ticket API endpoint |
| `ILGRIGIO_TICKET_API_KEY` | Ticket API authentication key |
| `MOLLIE_API_KEY` | Mollie payment API key |
| `MAX_TICKETS_PER_ORDER` | Ticket purchase limit per order |
| `TAX_RATE` | VAT rate (e.g. `9` for 9%) |
| `API_KEY` | API key for `OrderApiController` authentication |
| `MESSENGER_TRANSPORT_DSN` | SQS DSN for the `async` queue (internal app messages) |
| `MESSENGER_INGEST_TRANSPORT_DSN` | SQS DSN for the `webhook_ingest` queue (raw WooCommerce webhooks via API Gateway) |
