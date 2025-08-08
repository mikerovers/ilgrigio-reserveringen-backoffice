# Webhook Implementation with Symfony Webhook Component

This project now uses Symfony's webhook component for handling WooCommerce webhooks instead of regular HTTP routes.

## What was completed

✅ **PHPUnit Installation**: Installed Symfony test pack with PHPUnit 11.5.28  
✅ **Webhook Component Migration**: Migrated from route-based to event-driven webhook handling  
✅ **Request Parser**: Created `WooCommerceRequestParser` for parsing and validating webhooks  
✅ **Event Consumer**: Converted controller to `ConsumerInterface` implementation  
✅ **Configuration**: Updated services and webhook configurations  
✅ **Security**: Integrated signature validation with secret management  
✅ **Testing**: Created comprehensive unit and integration tests

## Architecture Overview

### 1. Request Parser (`App\Webhook\WooCommerceRequestParser`)

-   Extends `AbstractRequestParser` from Symfony webhook component
-   Validates HTTP method (POST only)
-   Parses JSON payload and validates structure
-   Verifies HMAC signatures using `WebhookSecurityService`
-   Converts HTTP requests to `RemoteEvent` objects
-   Handles different WooCommerce webhook topics (order.created, order.updated, etc.)

### 2. Event Consumer (`App\Controller\WooCommerceWebhookController`)

-   Implements `ConsumerInterface` for remote event processing
-   Processes webhook data and generates PDFs
-   Includes comprehensive error handling and logging
-   Maintains order validation logic

### 3. Security Service (`App\Service\WebhookSecurityService`)

-   Validates HMAC-SHA256 signatures from WooCommerce
-   Configurable webhook secret via environment variables
-   Order data validation (required fields, status filtering)

## Configuration Files

### Webhook Configuration (`config/packages/webhook.yaml`)

```yaml
framework:
    webhook:
        routing:
            woocommerce:
                service: 'App\Webhook\WooCommerceRequestParser'
                secret: "%env(WOOCOMMERCE_WEBHOOK_SECRET)%"
```

### Service Registration (`config/services.yaml`)

```yaml
# Webhook security service with environment secret
App\Service\WebhookSecurityService:
    arguments:
        $logger: "@logger"
        $webhookSecret: "%env(WOOCOMMERCE_WEBHOOK_SECRET)%"

# Request parser with webhook.request_parser tag
App\Webhook\WooCommerceRequestParser:
    arguments:
        $webhookSecurityService: '@App\Service\WebhookSecurityService'
        $logger: "@logger"
    tags:
        - { name: "webhook.request_parser", type: "woocommerce" }

# Event consumer with remote_event.consumer tag
App\Controller\WooCommerceWebhookController:
    tags:
        - { name: "remote_event.consumer", type: "woocommerce" }
```

## Testing

### Test Coverage

-   **Unit Tests**: `WooCommerceWebhookControllerTest` (2 tests)
-   **Parser Tests**: `WooCommerceRequestParserTest` (5 tests)
-   **Integration Tests**: `WebhookIntegrationTest` (3 tests)

### Running Tests

```bash
# Run all tests
php bin/phpunit

# Run specific test suites
php bin/phpunit tests/Webhook/
php bin/phpunit tests/Integration/
```

## Webhook Endpoint

**New URL**: `/webhook/woocommerce`  
**Method**: POST  
**Content-Type**: application/json  
**Headers**:

-   `X-WC-Webhook-Topic`: order.created, order.updated, etc.
-   `X-WC-Webhook-Signature`: HMAC signature (optional)

## Environment Variables

Set `WOOCOMMERCE_WEBHOOK_SECRET` in your `.env` file for signature validation:

```env
WOOCOMMERCE_WEBHOOK_SECRET=your-webhook-secret-here
```

## Migration Notes

### Before (Route-based)

-   Route: `/api/webhook/woocommerce-order-created`
-   Manual JSON parsing and validation
-   Direct HTTP response handling
-   Manual signature validation

### After (Event-driven)

-   Route: `/webhook/woocommerce` (handled by Symfony)
-   Automatic parsing via `RequestParser`
-   Event-driven processing via `ConsumerInterface`
-   Built-in signature validation
-   Better error handling and logging
-   Standardized HTTP responses

## Benefits

1. **Standardization**: Uses Symfony's built-in webhook handling patterns
2. **Error Handling**: Automatic retry mechanisms and proper HTTP responses
3. **Event-Driven**: Clean separation between parsing and processing
4. **Security**: Built-in signature validation with environment configuration
5. **Extensibility**: Easy to add more webhook types or consumers
6. **Testing**: Comprehensive test coverage with proper mocking
7. **Monitoring**: Better integration with Symfony's error handling and logging
8. **Type Safety**: Proper type hints and PHPStan compatibility

## Troubleshooting

If webhook processing fails in production, check:

1. `WOOCOMMERCE_WEBHOOK_SECRET` environment variable
2. Consumer service registration: `php bin/console debug:container --tag=remote_event.consumer`
3. Request parser registration: `php bin/console debug:container --tag=webhook.request_parser`
4. Webhook routing: `php bin/console debug:router | grep webhook`
