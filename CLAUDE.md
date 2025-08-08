# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Symfony 7.3 application that integrates with WooCommerce to handle event ticketing and order processing. The system processes webhooks from WooCommerce, generates PDF invoices, and manages secure PDF downloads with signed tokens.

## Key Technologies

- **PHP 8.2+** with Symfony 7.3 framework
- **DomPDF** for PDF generation
- **Symfony Mailer** for email notifications
- **Symfony Webhook** for WooCommerce webhook handling
- **PHPUnit** for testing
- **PHP_CodeSniffer** with PSR-12 standards

## Common Commands

### Development
```bash
# Start the development server
symfony server:start

# Run tests
./bin/phpunit

# Run specific test file
./bin/phpunit tests/Controller/PdfDownloadControllerTest.php

# Code style check
./vendor/bin/phpcs

# Fix code style issues
./vendor/bin/phpcbf
```

### Symfony Commands
```bash
# Clear cache
bin/console cache:clear

# Install assets
bin/console assets:install

# Debug routes
bin/console debug:router

# Debug services
bin/console debug:container
```

## Architecture Overview

### Core Components

1. **Controllers** (`src/Controller/`)
   - `TicketingController` - Main ticketing interface and event management
   - `WooCommerceWebhookController` - Processes WooCommerce order webhooks
   - `PdfDownloadController` - Handles secure PDF downloads via signed tokens
   - `HealthController` - System health checks
   - `TestController` - Development testing endpoints

2. **Services** (`src/Service/`)
   - `OrderPdfService` - Orchestrates PDF generation and email processing
   - `SecurePdfStorageService` - Manages signed tokens for secure PDF access
   - `TicketApiService` - Integrates with Il Grigio ticket API
   - `WooCommerceService` - Core WooCommerce API integration
   - `WooCommerceEventsService` - Event-specific WooCommerce operations
   - `WebhookSecurityService` - Validates WooCommerce webhook signatures

3. **Message Handling** (`src/Message/` & `src/MessageHandler/`)
   - `SendOrderEmailMessage` - Asynchronous email message
   - `SendOrderEmailMessageHandler` - Processes email sending with PDF attachments and download links

### Key Workflows

1. **Order Processing Flow**:
   WooCommerce Order → Webhook → Order Processing → PDF Generation → Email with Attachment + Download Link

2. **Secure PDF Access**:
   Email Link → Token Validation → On-demand PDF Generation → Download Response

3. **Ticketing Flow**:
   Event Selection → WooCommerce Integration → Order Creation → Confirmation

## Environment Configuration

Key environment variables to configure:

```env
# Email configuration
MAILER_DSN=smtp://your-smtp-server:587
MAILER_FROM_EMAIL=noreply@yourdomain.com
ADMIN_EMAIL=admin@yourdomain.com

# WooCommerce integration
WOOCOMMERCE_CONSUMER_KEY=ck_xxx
WOOCOMMERCE_CONSUMER_SECRET=cs_xxx
WOOCOMMERCE_WEBHOOK_SECRET=webhook_secret
ILGRIGIO_BASE_URL=https://yourdomain.com
ILGRIGIO_BASE_API_URL=https://yourdomain.com/wp-json

# PDF security
PDF_TOKEN_SECRET=your-super-secret-key-for-pdf-tokens-change-this-in-production
PDF_TOKEN_EXPIRATION_DAYS=150

# Ticket API
ILGRIGIO_TICKET_API_URL=https://api.example.com/tickets
ILGRIGIO_TICKET_API_KEY=your_api_key

# Application settings
MAX_TICKETS_PER_ORDER=10
TAX_RATE=0.21
```

## Testing

- PHPUnit configuration in `phpunit.xml.dist`
- Test files in `tests/` directory
- Integration tests for webhook processing
- Unit tests for PDF download controller
- Service-level tests for core functionality

## Code Standards

- Follows PSR-12 coding standards
- PHP_CodeSniffer configuration in `phpcs.xml.dist`
- Covers `bin/`, `config/`, `public/`, `src/`, and `tests/` directories

## Security Features

- HMAC-SHA256 signed tokens for PDF access
- Webhook signature verification
- Configurable token expiration (default: 150 days)
- No token storage - stateless token system
- Environment-based secrets management

## PDF System

The secure PDF download system:
- Generates signed tokens containing order data
- Provides both email attachments AND secure download links
- On-demand PDF generation (no caching)
- Self-contained tokens with cryptographic verification
- Multi-language support (Dutch, English, German)