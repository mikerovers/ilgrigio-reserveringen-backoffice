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
     `maxReceiveCount = 4`). This SQS-level redrive catches **infrastructure**
     failures — the worker crashing/being killed mid-process or the
     `visibility_timeout` expiring before processing finishes — where the message is
     received repeatedly without being deleted. **Application-level** failures are
     handled separately by Messenger (see below); both land in this same DLQ.

   > **Two DLQ paths, one queue.** Each Messenger retry is re-sent as a *new* SQS
   > message, so it does not increment the original's receive count. App failures
   > therefore never exhaust `maxReceiveCount`; instead the `webhook_ingest`
   > transport's `failure_transport` (`webhook_ingest_failed`, see
   > `config/packages/messenger.yaml`) routes them here. The redrive policy only
   > backstops infra failures Messenger never gets to see.

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
   - Set `MESSENGER_INGEST_DLQ_TRANSPORT_DSN` (SECRET in `.do/app.yaml`) to the DLQ
     queue DSN, e.g.
     `sqs://default?queue_name=ilgrigio-reserveringen-ingest-dlq&region=eu-central-1&access_key=...&secret_key=...`
     This backs the `webhook_ingest_failed` failure transport. Inspect/redrive
     dead-lettered messages with
     `bin/console messenger:failed:show --transport=webhook_ingest_failed` and
     `... messenger:failed:retry --transport=webhook_ingest_failed` (the
     `--transport=` flag is required — there is no single global failure transport).
     The worker does **not** consume this transport, so failures accumulate for
     inspection.

5. **WooCommerce** — set the Delivery URL to the API Gateway invoke URL (see
   "Switching routes").

> Prefer managing the AWS resources via Terraform so they are reviewable and
> reproducible; the steps above describe the equivalent console setup.

## Hardening (Route A edge protection)

The API Gateway invoke URL is **public and unauthenticated at the gateway** — the
HMAC signature is only checked later, in the worker. To stop junk/abusive POSTs
from costing SQS writes and worker cycles (and to make the endpoint effectively
private to the WooCommerce host), the following edge controls are applied in
account `312666357942`, region `eu-central-1`, on API `uogbwuxq2a` stage `prod`.

> **CORS is intentionally NOT configured.** WooCommerce (Cloudways PHP backend)
> calls this endpoint server-to-server; no browser is in the path, so CORS would
> be security theater. The real controls are below.

### 1. AWS WAF — IP allow-list + rate cap (strongest control)

- **IPSet** `woocommerce-ingest-allow` (REGIONAL, IPv4) — currently
  `146.190.236.205/32` (Cloudways WooCommerce host egress IP).
- **WebACL** `woocommerce-ingest-acl`, **default action `Block`**, associated with
  the stage (`.../restapis/uogbwuxq2a/stages/prod`). Rules:
  - Priority 0 `allow-cloudways-ip` (**Allow**) → matches the IPSet. Only this IP
    passes; everything else hits the default Block (`403`).
  - Priority 1 `rate-limit-per-ip` (**Block**) → rate-based, 300 req / 5 min per
    source IP. Backstop in case the allow-list is widened.

> **If Cloudways changes/adds egress IPs, update the IPSet — otherwise webhooks
> get a 403 and orders stop arriving.** Get the lock token, then update:
> ```bash
> aws wafv2 get-ip-set --scope REGIONAL --region eu-central-1 \
>   --name woocommerce-ingest-allow \
>   --id 535a1f5d-3b98-403d-8768-377272235d71 --query LockToken --output text
> aws wafv2 update-ip-set --scope REGIONAL --region eu-central-1 \
>   --name woocommerce-ingest-allow \
>   --id 535a1f5d-3b98-403d-8768-377272235d71 \
>   --addresses 146.190.236.205/32 <NEW_IP>/32 \
>   --lock-token <TOKEN_FROM_ABOVE>
> ```

### 2. Method-level throttling

Stage method settings for `*/*`: `throttlingRateLimit = 10`,
`throttlingBurstLimit = 20`. Caps the SQS write rate independently of WAF.
(See the note below on how this differs from a WAF rate-based rule / Bot Control
or other "protection pack" managed rule groups.)

### 3. Request validation at the gateway

Request validator `require-webhook-headers` (`validateRequestParameters=true`)
attached to `POST /woocommerce`; the method marks
`method.request.header.x-wc-webhook-signature` and
`method.request.header.x-wc-webhook-topic` as **required**, so missing-header junk
is rejected with `400` at the edge instead of becoming a dead-lettered SQS
message. **Gateway changes require a stage redeploy to take effect**
(`aws apigateway create-deployment --stage-name prod`).

### 4. CloudWatch alarms → SNS

- SNS topic `ilgrigio-ingest-alerts`; email subscription
  `reserveringen@ilgrigio.nl` — **must be confirmed via the AWS email** or no
  notifications are delivered.
- `ilgrigio-ingest-dlq-not-empty` — `AWS/SQS ApproximateNumberOfMessagesVisible`
  on the DLQ `> 0` (bad-signature flood or processing failure).
- `ilgrigio-ingest-apigw-4xx-spike` — `AWS/ApiGateway 4XXError` sum `> 50` / 5 min
  (probing / WAF-blocked surge / malformed requests).

### 5. Stage access logging

Access logs go to CloudWatch Logs group `/aws/apigateway/woocommerce-ingest`
(30-day retention) as JSON including `sourceIp`, `status`, `wafResponse`,
`responseLatency`. Requires the account-level role `apigw-cloudwatch-logs`
(`AmazonAPIGatewayPushToCloudWatchLogs`), set once via
`apigateway update-account /cloudwatchRoleArn`.

### Already in place (verified, unchanged)

- IAM role `apigw-woocommerce-sqs-send` is scoped to `sqs:SendMessage` on the one
  ingest queue ARN only.
- Ingest queue redrive → DLQ with `maxReceiveCount = 4`.

## Validation

- Post a sample order JSON with a valid `X-WC-Webhook-Signature` (HMAC-SHA256,
  base64, using `WOOCOMMERCE_WEBHOOK_SECRET`) to the API Gateway URL via `curl`;
  confirm a message lands on the ingest queue, the worker processes it, and the
  e-ticket email is delivered.
- Post with a wrong signature; confirm it is rejected
  (`UnrecoverableMessageHandlingException`) and routed by the `failure_transport` to
  the DLQ — no email sent. Verify with
  `bin/console messenger:failed:show --transport=webhook_ingest_failed`.
