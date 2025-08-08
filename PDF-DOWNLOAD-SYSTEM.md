# Secure PDF Download System

## Overview

This system provides secure PDF downloads for order confirmations via email links **AND** attachments. The PDFs are generated on-demand and accessed through secure signed tokens that contain the order data.

## Features

-   **Both Download Links AND Attachments**: Emails include both PDF attachments and secure download links
-   **Signed Tokens**: Self-contained tokens using HMAC-SHA256 signatures with environment secret
-   **No Token Storage**: Tokens contain all necessary data and are verified cryptographically
-   **On-Demand Generation**: PDFs are generated when requested, not stored on disk
-   **Configurable Expiration**: Tokens expire after a configurable time period (default: 150 days ~ 5 months)
-   **No Caching**: PDFs are freshly generated on each request
-   **Email Integration**: Download links AND attachments included in both admin and customer emails
-   **Multi-language Support**: Email content supports Dutch, English, and German

## How It Works

1. **Order Processing**: When an order is processed via `OrderPdfService::processOrder()`:

    - A secure signed token is generated containing the order data and expiration time
    - Token is signed using HMAC-SHA256 with `PDF_TOKEN_SECRET` from environment
    - Token expires after `PDF_TOKEN_EXPIRATION_DAYS` days (default: 150 days ~ 5 months)
    - An email message is dispatched with the token

2. **Email Sending**: The `SendOrderEmailMessageHandler`:

    - Generates PDF content from the token for attachment
    - Generates a download URL using the token
    - Sends emails to admin and customer with BOTH PDF attachment AND download link

3. **PDF Download**: When accessing `/pdf/download/{token}`:
    - Token signature is verified using environment secret
    - Token expiration time is checked
    - Order data is extracted from token payload (if valid and not expired)
    - PDF is generated on-demand using Dompdf
    - PDF is returned as a download response

## Token Structure

Tokens follow a three-part structure: `header.payload.signature`

-   **Header**: `{"typ":"TOKEN","alg":"HS256"}`
-   **Payload**: `{"orderData":{...},"iat":timestamp,"exp":expiration_timestamp,"jti":"unique_id"}`
-   **Signature**: HMAC-SHA256 hash of header.payload using `PDF_TOKEN_SECRET`

## API Endpoints

### PDF Download

```
GET /pdf/download/{token}
```

-   **Parameters**: `token` - 64-character hexadecimal secure token
-   **Response**: PDF file download with appropriate headers
-   **Error Handling**: Returns 404 for invalid tokens

### Test Endpoint

```
GET /test/order-pdf
```

-   Processes a sample order for testing
-   Generates secure token and sends test emails

## File Structure

```
src/
├── Controller/
│   └── PdfDownloadController.php    # Handles secure PDF downloads
├── Service/
│   ├── SecurePdfStorageService.php  # Token generation and PDF creation
│   └── OrderPdfService.php          # Order processing with tokens
├── MessageHandler/
│   └── SendOrderEmailMessageHandler.php  # Email sending with download links
└── Message/
    └── SendOrderEmailMessage.php    # Updated to use tokens instead of content

translations/
├── messages.nl.yaml    # Dutch translations
├── messages.en.yaml    # English translations
└── messages.de.yaml    # German translations

tests/
└── Controller/
    └── PdfDownloadControllerTest.php  # Unit tests for PDF download
```

## Security Features

1. **Signed Tokens**: Self-contained tokens with HMAC-SHA256 signatures
2. **Environment Secret**: Uses `PDF_TOKEN_SECRET` environment variable for signing
3. **Token Expiration**: Configurable expiration time (default: 150 days ~ 5 months)
4. **No Token Storage**: No database or file storage needed - tokens are stateless
5. **Cryptographic Verification**: Each token signature is verified before use
6. **Expiration Validation**: Tokens are checked for expiration on each access
7. **Logged Access**: All download attempts are logged for security monitoring
8. **Token Truncation in Logs**: Only first 8 characters logged to prevent exposure

## Environment Configuration

Add to your `.env` file:

```env
PDF_TOKEN_SECRET=your-super-secret-key-for-pdf-tokens-change-this-in-production
PDF_TOKEN_EXPIRATION_DAYS=150
```

**Important**:

-   Use a strong, unique secret in production (minimum 32 characters)
-   Adjust expiration time as needed (value in days, default: 150 days ~ 5 months)

## Storage

No storage required! Tokens are self-contained and stateless:

-   Order data is embedded in the token payload
-   Tokens are verified using the environment secret
-   No database or file system storage needed
-   Perfect for distributed/load-balanced systems

## Configuration

The system uses the following service configuration in `config/services.yaml`:

```yaml
App\Service\SecurePdfStorageService:
    arguments:
        $tokenSecret: "%env(PDF_TOKEN_SECRET)%"
        $tokenExpirationDays: "%env(int:default:150:PDF_TOKEN_EXPIRATION_DAYS)%"

App\MessageHandler\SendOrderEmailMessageHandler:
    arguments:
        $fromEmail: "%env(default:default_from_email:MAILER_FROM_EMAIL)%"
        $adminEmail: "%env(default:default_admin_email:ADMIN_EMAIL)%"
```

## Testing

Run the included tests:

```bash
./bin/phpunit tests/Controller/PdfDownloadControllerTest.php
```

Test the system with sample data:

```bash
curl http://localhost:8000/test/order-pdf
```

## Translation Keys

New translation keys added:

```yaml
email:
    download_link: "Download your PDF confirmation: %download_url%"
```

Available in Dutch (`nl`), English (`en`), and German (`de`).

## Migration Notes

This implementation:

-   ✅ Provides BOTH download links AND attachments in emails
-   ✅ Generates PDFs on-demand (not saved)
-   ✅ Uses secure, self-contained signed tokens
-   ✅ No token storage required (stateless)
-   ✅ Configurable token expiration (default: 150 days ~ 5 months)
-   ✅ Does not cache PDFs
-   ✅ Includes mocked data support
-   ✅ Uses environment secret for security
-   ✅ Maintains existing email functionality with attachments
