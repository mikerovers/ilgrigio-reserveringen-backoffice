# Il Grigio Reservations Backoffice

Symfony 8.2 application for event reservations, ticket sales, payments, and
backoffice operations. Orders are created and stored in this application.
Online payments use Mollie; completed orders issue tickets and send confirmation
emails through the Messenger `email` transport. The register API supports
in-person cash sales.

## Local setup

Requirements: PHP 8.4+, Composer, and PostgreSQL. See `AGENTS.md` for the
application architecture, API behavior, security boundaries, and coding
guidance.

1. Install PHP dependencies:

   ```bash
   composer install
   ```

2. Copy `.env` to `.env.local` and configure the local database, application
   secret, mail transport, Mollie API key, JWT keys, PDF token secret, and the
   application base URL. Do not commit secrets. The complete variable list is
   in `AGENTS.md` and defaults/examples are in `.env`.

3. Apply database migrations, then start the development server:

   ```bash
   bin/console doctrine:migrations:migrate
   symfony server:start
   ```

4. Order emails use `MESSENGER_EMAIL_TRANSPORT_DSN`. The default `.env` value
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
Functional tests use PostgreSQL; configure a separate test database before
running them.

## Order and payment flow

- The storefront creates a local order and starts a Mollie payment.
- Mollie sends payment updates to `/webhook/mollie`; the application retrieves
  authoritative payment status from Mollie before changing the order.
- Paid and zero-total orders are fulfilled idempotently, with tickets and a
  signed PDF download link, and confirmation mail is dispatched on the `email`
  transport.
- The backoffice uses EasyAdmin. The authenticated register API supports cash
  sales, order lookup and cancellation, notes, reprocessing, event and coupon
  lookup, and sale reports.

For queue inspection and failed-message recovery, use
`bin/console messenger:stats`, `messenger:failed:show`,
`messenger:failed:retry`, and `messenger:failed:remove`.
