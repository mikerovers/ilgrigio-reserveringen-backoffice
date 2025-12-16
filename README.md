# Il Grigio Reservations Backoffice

Symfony application that integrates with WooCommerce to handle event ticketing, order processing, and secure PDF invoice generation.

## Setup

### 1. Install Dependencies

```bash
composer install
```

### 2. Configure Environment

Copy `.env` to `.env.local` and configure:

```env
# Email
MAILER_DSN=smtp://your-smtp-server:587
MAILER_FROM_EMAIL=noreply@yourdomain.com
ADMIN_EMAIL=admin@yourdomain.com

# WooCommerce
WOOCOMMERCE_CONSUMER_KEY=ck_xxx
WOOCOMMERCE_CONSUMER_SECRET=cs_xxx
WOOCOMMERCE_WEBHOOK_SECRET=webhook_secret
ILGRIGIO_BASE_URL=https://yourdomain.com
ILGRIGIO_BASE_API_URL=https://yourdomain.com/wp-json

# PDF Security
PDF_TOKEN_SECRET=your-super-secret-key-change-this
PDF_TOKEN_EXPIRATION_DAYS=150

# Ticket API
ILGRIGIO_TICKET_API_URL=https://api.example.com/tickets
ILGRIGIO_TICKET_API_KEY=your_api_key
```

### 3. Configure WooCommerce Webhook

In WooCommerce admin:
- Go to **Settings > Advanced > Webhooks**
- Add webhook for **Order created**
- Set URL: `https://yourdomain.com/api/webhook/woocommerce-order-created`

## Running

```bash
# Start development server
symfony server:start

# Run tests
./bin/phpunit

# Check code style
./vendor/bin/phpcs

# Fix code style
./vendor/bin/phpcbf
```

## Features

- WooCommerce order webhook processing
- PDF invoice generation with secure signed tokens
- Email notifications with attachments and download links
- Event ticketing management
- Multi-language support (NL, EN, DE)
