# Il Grigio Reservations Backoffice

Symfony 8.2 application for event reservations, ticket sales, payments, and
backoffice operations. It contains four apps on separate hosts:

| App | Host variable | Local value | Purpose |
|---|---|---|---|
| Storefront | (default host) | `localhost` | Public ticket shop, Mollie payments, optional customer login |
| Backoffice | `ADMIN_HOST` | `admin.localhost` | EasyAdmin |
| Scanner | `SCANNER_HOST` | `scanner.localhost` | Door check-in (`ROLE_CHECK_IN`) |
| Register (kassa) | `REGISTER_HOST` | `kassa.localhost` | In-person sales, orders, coupons (`ROLE_REGISTER`) |

Host variables are bare hostnames without a port. When using the dev server,
add the port in the browser (e.g. `http://admin.localhost:8000`).

A JWT-based register/check-in API is available under `/api`. Orders are created
and stored in this application. Online payments use Mollie; completed orders
issue tickets and send confirmation emails through the Messenger `email`
transport.

## Local setup

Requirements: PHP 8.4+, Composer, and PostgreSQL. See `AGENTS.md` for the
application architecture, API behavior, security boundaries, and coding
guidance.

1. Install PHP dependencies:

   ```bash
   composer install
   ```

2. Copy `.env` to `.env.local` and configure the local database, application
   secret, mail transport, Mollie API key, JWT keys, PDF token secret, the
   application base URL, and the three host variables (`ADMIN_HOST`,
   `SCANNER_HOST`, `REGISTER_HOST`). Do not commit secrets. The complete
   variable list is in `AGENTS.md` and defaults/examples are in `.env`.

3. Apply database migrations and load fixtures if you want sample data:

   ```bash
   bin/console doctrine:migrations:migrate
   bin/console doctrine:fixtures:load   # optional, wipes the database
   ```

4. Build the CSS. Tailwind is compiled by the standalone CLI (no Node), and in
   dev pages error until it has been built once. Keep the watcher running while
   you work on templates:

   ```bash
   bin/console tailwind:build --watch
   ```

   On macOS the downloaded binary may be killed (exit 137); see `AGENTS.md`.

5. Start the development server:

   ```bash
   symfony server:start
   ```

6. Create users for the backoffice, register and scanner (prompts for email,
   name, password and roles):

   ```bash
   bin/console app:user:create
   ```

7. Order emails use `MESSENGER_EMAIL_TRANSPORT_DSN`. The default `.env` value
   is `sync://`, so they are sent in the request that queues them. When that
   DSN is a Doctrine queue, run a worker in a separate terminal:

   ```bash
   bin/console messenger:consume email -vv
   ```

## Common commands

```bash
./bin/phpunit
./vendor/bin/phpcs
./vendor/bin/phpcbf
bin/console debug:router
```

Run a focused test with `./bin/phpunit tests/Service/CheckoutServiceTest.php`.

Functional tests use PostgreSQL and need their own database. Point
`DATABASE_URL` at a separate test database, then migrate it once:

```bash
export DATABASE_URL="postgresql://user:pass@127.0.0.1:5432/app_test"
APP_ENV=test bin/console doctrine:migrations:migrate -n
```

## Order and payment flow

- The storefront creates a local order, reserves stock and starts a Mollie
  payment.
- Mollie sends payment updates to `/webhook/mollie`; the application retrieves
  authoritative payment status from Mollie before changing the order.
- Paid and zero-total orders are fulfilled idempotently, with tickets and a
  signed PDF download link, and confirmation mail is dispatched on the `email`
  transport.
- The register app and the JWT API support cash sales, order lookup,
  cancellation, rebooking, notes, reprocessing, event and coupon lookup, and
  sale reports.

For queue inspection and failed-message recovery, use
`bin/console messenger:stats`, `messenger:failed:show`,
`messenger:failed:retry`, and `messenger:failed:remove`.

## Scheduled jobs

Run these from cron in production:

| Command | Frequency | Purpose |
|---|---|---|
| `bin/console app:orders:expire-pending` | every minute | Releases stock held by unpaid orders after `ORDER_RESERVATION_TTL_MINUTES` |
| `bin/console app:login-codes:purge` | daily | Deletes expired customer login codes |

## Optional features

- **Customer login:** set `CUSTOMER_LOGIN_ENABLED=1` to enable the passwordless
  `/inloggen` and `/mijn-tickets` pages. When `0` (the default) they return 404.
- **Manual order completion:** `bin/console app:orders:complete <orderNumber>`
  completes a stuck order and issues its tickets and email.
