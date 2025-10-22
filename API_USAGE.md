# Order Processing API

## Endpoint

**POST** `/api/orders/{orderId}/process`

## Authentication

The API uses simple API key authentication. Include your API key in the request header:

```
X-API-KEY: 51614179e60fdbe79271773ad044af4adaacaef13bb0206d9d73dffee75671cb
```

## Request Format

Send a POST request with the order ID in the URL path. The order data will be automatically fetched from WooCommerce.

### URL Parameters

- `orderId` (required) - The WooCommerce order ID (integer)

## Example cURL Request

```bash
curl -X POST https://backoffice.ilgrigio.nl/api/orders/12345/process \
  -H "X-API-KEY: 51614179e60fdbe79271773ad044af4adaacaef13bb0206d9d73dffee75671cb"
```

## Response Format

### Success Response (200 OK)

```json
{
  "success": true,
  "message": "Order processed successfully",
  "order_id": 12345
}
```

### Error Responses

#### Order Not Found (404 Not Found)

```json
{
  "error": "Order not found",
  "message": "Order with ID 12345 could not be found in WooCommerce"
}
```

#### Invalid Order Data (400 Bad Request)

```json
{
  "error": "Invalid order data",
  "message": "Order data is missing required fields"
}
```

#### Authentication Failed (401 Unauthorized)

```json
{
  "error": "Authentication failed",
  "message": "Invalid API key"
}
```

#### Server Error (500 Internal Server Error)

```json
{
  "error": "Failed to process order",
  "message": "Detailed error message"
}
```

## What Happens When You Call This API

1. The API validates your API key
2. Fetches the order data from WooCommerce using the provided order ID
3. Validates the order data has required fields (id, billing)
4. Calls `OrderPdfService::processOrder()` which:
   - Generates a secure token for PDF download
   - Dispatches an async message to send email with PDF attachment and download link
5. Returns a success response

## Environment Configuration

The API key is configured in your `.env` file:

```env
API_KEY=51614179e60fdbe79271773ad044af4adaacaef13bb0206d9d73dffee75671cb
```

**IMPORTANT**: Change this key in production and keep it secure!

## Security Features

- Stateless authentication (no session storage)
- API key validation on every request
- Cryptographically secure token generation
- No database lookups for authentication
