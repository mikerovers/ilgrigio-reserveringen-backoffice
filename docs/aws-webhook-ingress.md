# WooCommerce webhook ingress

Completed WooCommerce orders reach this application through **one** of two
interchangeable ingress routes. Exactly one is configured as the WooCommerce
webhook **Delivery URL** at any time — they are never enabled simultaneously, so
no order is delivered twice and no de-duplication is required.

```
Route A (API Gateway → SQS, resilient):
  WooCommerce → API Gateway (SQS integration) → ingest queue
              → IncomingWooCommerceWebhookMessageHandler
                 (validate signature, normalize, processOrder)
                                                   │
Route B (app HTTP endpoint, original):            │
  WooCommerce → /webhook (WooCommerceRequestParser + Controller)
              → processOrder() ───────────────────┘
                                                   ↓
              → SendOrderEmailMessage (async queue) → e-ticket email
```

Both routes converge on `OrderPdfService::processOrder()`; everything downstream
(PDF generation, e-ticket email) is shared and unchanged.

## Why Route A exists

Route B puts the app's single `basic-xxs` web service on the critical delivery
path. During a deploy/restart or transient error it can fail to return HTTP 200,
and WooCommerce then drops (and eventually auto-disables) the webhook — the
customer never gets their ticket. Route A delivers to AWS API Gateway + SQS
(highly available, managed), so the app web service is off the delivery path.

## Switching routes

Set the WooCommerce webhook **Delivery URL** (WooCommerce → Settings → Advanced →
Webhooks) to either:

- **Route A:** the API Gateway invoke URL (e.g.
  `https://<api-id>.execute-api.<region>.amazonaws.com/prod/woocommerce`)
- **Route B:** the app endpoint (the URL currently configured for the
  `woocommerce` webhook consumer)

Keep the **Secret** set to the same value as `WOOCOMMERCE_WEBHOOK_SECRET`. The
inactive route simply receives no traffic; both remain deployed and functional.

> Switching is a manual WooCommerce config change, not automatic failover. If the
> active route breaks, flip the Delivery URL to the other route.

## Route A — AWS setup (API Gateway → SQS, no Lambda)

1. **SQS queues**
   - Create the ingest queue, e.g. `ilgrigio-reserveringen-ingest`
     (`region=eu-central-1`).
   - Create a dead-letter queue, e.g. `ilgrigio-reserveringen-ingest-dlq`, and set
     a **redrive policy** on the ingest queue pointing at it (e.g.
     `maxReceiveCount = 4`, matching the 3 Messenger retries + 1 initial receive).
     Payloads that repeatedly fail validation/processing land here for inspection
     instead of being lost.

2. **API Gateway (REST API)**
   - Resource `POST /woocommerce` with an **AWS service integration** to SQS
     `SendMessage` (no Lambda).
   - **Integration request mapping template** — pass the body through as the SQS
     message body and forward the WooCommerce headers as SQS **message
     attributes** (String). These are required because signature validation
     happens in the worker. Map at least:
     - `X-WC-Webhook-Signature`
     - `X-WC-Webhook-Topic`  (required; the serializer rejects messages without it)
     - `X-WC-Webhook-Event`  (optional)

     Example `application/json` integration template (SQS query-param form):
     ```
     Action=SendMessage
     &MessageBody=$util.urlEncode($input.body)
     &MessageAttribute.1.Name=X-WC-Webhook-Signature
     &MessageAttribute.1.Value.StringValue=$util.urlEncode($input.params().header.get('X-WC-Webhook-Signature'))
     &MessageAttribute.1.Value.DataType=String
     &MessageAttribute.2.Name=X-WC-Webhook-Topic
     &MessageAttribute.2.Value.StringValue=$util.urlEncode($input.params().header.get('X-WC-Webhook-Topic'))
     &MessageAttribute.2.Value.DataType=String
     &MessageAttribute.3.Name=X-WC-Webhook-Event
     &MessageAttribute.3.Value.StringValue=$util.urlEncode($input.params().header.get('X-WC-Webhook-Event'))
     &MessageAttribute.3.Value.DataType=String
     ```
   - Set the integration `Content-Type` to
     `application/x-www-form-urlencoded` and require an integration response
     mapping that returns `200` to WooCommerce on success.

3. **IAM** — an API Gateway execution role scoped to `sqs:SendMessage` on the
   ingest queue only.

4. **App configuration**
   - Set `MESSENGER_INGEST_TRANSPORT_DSN` (SECRET in `.do/app.yaml`) to the ingest
     queue DSN, e.g.
     `sqs://default?queue_name=ilgrigio-reserveringen-ingest&region=eu-central-1&access_key=...&secret_key=...`
   - The `messenger-consumer` worker already consumes `webhook_ingest` (see the
     `messenger:consume async webhook_ingest` command in `Dockerfile`).
   - The `webhook_ingest` transport uses `auto_setup: false` — the queue and DLQ
     are created by this AWS setup, not by Messenger.

5. **WooCommerce** — set the Delivery URL to the API Gateway invoke URL (see
   "Switching routes").

> Prefer managing the AWS resources via Terraform so they are reviewable and
> reproducible; the steps above describe the equivalent console setup.

## Validation

- Post a sample order JSON with a valid `X-WC-Webhook-Signature` (HMAC-SHA256,
  base64, using `WOOCOMMERCE_WEBHOOK_SECRET`) to the API Gateway URL via `curl`;
  confirm a message lands on the ingest queue, the worker processes it, and the
  e-ticket email is delivered.
- Post with a wrong signature; confirm it is rejected
  (`UnrecoverableMessageHandlingException`) and lands in the DLQ — no email sent.
