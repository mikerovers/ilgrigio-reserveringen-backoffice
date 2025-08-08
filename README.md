# WooCommerce Webhook Integration

This Symfony application receives webhooks from WooCommerce when orders are created, generates PDF invoices, and sends them via email.

## Features

-   Receives WooCommerce order creation webhooks
-   Generates PDF invoices using DomPDF
-   Sends email notifications to admin and customers
-   Configurable email settings
-   Comprehensive logging
-   Test endpoint for development

## Setup

### 1. Install Dependencies

Dependencies are already installed via Composer:

-   `symfony/webhook` - For webhook handling
-   `symfony/mailer` - For email sending
-   `dompdf/dompdf` - For PDF generation

### 2. Configure Environment Variables

Update your `.env` file with the following configurations:

```bash
# Email configuration
MAILER_DSN=smtp://your-smtp-server:587
# For development, you can use a local mail server like MailHog:
# MAILER_DSN=smtp://localhost:1025

# Application email addresses
FROM_EMAIL=noreply@yourdomain.com
ADMIN_EMAIL=admin@yourdomain.com

# Optional: WooCommerce webhook secret for security
WOOCOMMERCE_WEBHOOK_SECRET=your-webhook-secret
```

### 3. WooCommerce Webhook Configuration

In your WooCommerce admin panel:

1. Go to **WooCommerce > Settings > Advanced > Webhooks**
2. Click **Add webhook**
3. Configure the webhook:
    - **Name**: Order Created Notification
    - **Status**: Active
    - **Topic**: Order created
    - **Delivery URL**: `https://yourdomain.com/api/webhook/woocommerce-order-created`
    - **Secret**: (optional, for security)
    - **API Version**: WC API Integration v3

### 4. Email Server Setup

For production, configure a proper SMTP server:

```bash
# Example Gmail SMTP (use app password)
MAILER_DSN=smtp://username:password@smtp.gmail.com:587

# Example SendGrid
MAILER_DSN=smtp://apikey:your-sendgrid-api-key@smtp.sendgrid.net:587

# Example Mailgun
MAILER_DSN=smtp://username:password@smtp.mailgun.org:587
```

For development, you can use [MailHog](https://github.com/mailhog/MailHog):

```bash
# Install MailHog (macOS)
brew install mailhog

# Run MailHog
mailhog

# Use this DSN in your .env
MAILER_DSN=smtp://localhost:1025
```

## Usage

### Webhook Endpoint

The webhook endpoint is available at:

```
POST /api/webhook/woocommerce-order-created
```

This endpoint:

-   Accepts WooCommerce order creation webhooks
-   Validates the payload
-   Skips draft orders
-   Generates PDF invoices
-   Sends emails to admin and customer
-   Returns appropriate HTTP status codes

### Test Endpoint

For testing purposes, you can use:

```
GET /test/order-pdf
```

This endpoint processes a sample order to test PDF generation and email sending.

## PDF Generation

The system generates comprehensive PDF invoices including:

-   Order details (number, date, status)
-   Customer billing information
-   Shipping information (if different)
-   Itemized product list
-   Order totals (subtotal, tax, shipping, total)
-   Professional styling

## Email Notifications

Two emails are sent for each order:

1. **Admin Notification**: Sent to the configured admin email address
2. **Customer Confirmation**: Sent to the customer's billing email address

Both emails include the PDF invoice as an attachment.

## Logging

All webhook processing is logged including:

-   Webhook reception
-   Order processing success/failure
-   Email sending status
-   Error details

Logs can be found in `var/log/dev.log` (development) or `var/log/prod.log` (production).

## Security Considerations

1. **HTTPS**: Use HTTPS for webhook endpoints in production
2. **Webhook Secret**: Configure a webhook secret in WooCommerce and validate it
3. **Rate Limiting**: Consider implementing rate limiting for webhook endpoints
4. **Input Validation**: The system validates webhook payloads

## Error Handling

The system handles various error scenarios:

-   Invalid webhook payloads
-   Missing required order data
-   PDF generation failures
-   Email sending failures
-   Network connectivity issues

All errors are logged and appropriate HTTP status codes are returned.

## Customization

### PDF Template

To customize the PDF layout, modify the `generateOrderHtml()` method in `src/Service/OrderPdfService.php`.

### Email Content

Email content can be customized in the `sendOrderNotification()` method in `src/Service/OrderPdfService.php`.

### Additional Order Processing

Add custom logic in the `processOrder()` method in `src/Service/OrderPdfService.php`.

## Troubleshooting

### Common Issues

1. **Emails not sending**:

    - Check MAILER_DSN configuration
    - Verify SMTP server credentials
    - Check firewall settings

2. **PDF generation fails**:

    - Ensure DomPDF can write to temp directories
    - Check for missing fonts
    - Verify memory limits

3. **Webhooks not received**:
    - Verify webhook URL is accessible
    - Check WooCommerce webhook logs
    - Ensure HTTPS is properly configured

### Debug Mode

Enable debug logging by setting `APP_ENV=dev` in your `.env` file.

## Development

### Running Tests

```bash
# Test the order processing system
curl https://yourdomain.com/test/order-pdf

# Test webhook endpoint (with sample payload)
curl -X POST https://yourdomain.com/webhook/woocommerce/order-created \
  -H "Content-Type: application/json" \
  -d '{"id": 123, "status": "processing", ...}'
```

### Local Development

For local development with MailHog:

1. Install and run MailHog
2. Set `MAILER_DSN=smtp://localhost:1025`
3. View emails at http://localhost:8025

## Production Deployment

1. Set `APP_ENV=prod`
2. Configure proper SMTP server
3. Use HTTPS for webhook endpoints
4. Set up proper logging and monitoring
5. Configure webhook secrets for security
