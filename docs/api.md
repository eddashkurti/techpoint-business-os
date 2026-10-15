# Application JSON/REST contract

This release exposes authenticated session routes for its own UI and JSON clients. Send `Accept: application/json`; POST requests require the Laravel CSRF token and authenticated cookie. HTTPS is required in production. Owners can also create scoped read-only bearer tokens. Permissions are evaluated on the server for each request, including requests made outside the UI.

## Input and responses

Money, quantities, rates and costs must be JSON strings such as `"5.00"` and `"2.000000"`. IDs are integers. Dates use ISO `YYYY-MM-DD`. All effectful posting requests contain a stable `operation_key` of 8–100 characters, preferably a UUID. Retry the identical request with the same key. Do not generate a new key after an unknown outcome. A changed payload under an existing key produces HTTP 422; duplicates protected by database identities may produce HTTP 409. Validation/domain errors use HTTP 422; unauthenticated/forbidden/not-found requests use standard 401/403/404 behavior.

Creation returns `{ "id": 123 }` with HTTP 201 for generic records. Transaction operations return an ID/result with HTTP 200. Detail requests return `{ "record": {...}, "lines": [...] }`. List pages use Inertia rather than a public paginated JSON contract. Catalog lookup returns a bounded JSON array. Fiscal confirmations are accepted only through the configured provider adapter, not a public client-controlled success flag.

| Method/path | Purpose | Permission |
|---|---|---|
| GET /catalog/search?barcode=... | Exact barcode lookup | inventory.view |
| POST /sales/quote | Server-calculated prices, tax and totals | sales.manage |
| POST /products, /categories | Catalog/category creation | inventory.manage |
| POST /products/{id}/edit, /condition | Catalog edit or physical condition transfer | inventory.manage |
| POST /purchases | Purchase order; no stock change | purchasing.manage |
| POST /purchases/{id}/costs, /receive, /supplier-return | Cost/physical operations | purchasing.manage |
| POST /stock/adjust | Signed correction with documented value/reason | inventory.adjust |
| POST /stock-counts; /stock-counts/{id}/confirm | Draft/confirmed physical count | inventory.manage / inventory.adjust |
| POST /sales | Accept and reserve order | sales.manage |
| POST /sales/{id}/dispatch, /cancel, /payments | Fulfilment/reservation/recognized-sale settlement | sales.manage |
| POST /fulfillments/{id}/deliver | Delivery confirmation/revenue recognition | sales.manage |
| POST /sales/{id}/credits | Financial return credit | sales.refund |
| POST /returns/{id}/receive, /refund | Physical return/cash refund | inventory.manage / sales.refund |
| POST /deliveries/{id}/collect, /failed, /returned-parcel | Courier/parcel handling | finance.manage / sales.manage / inventory.manage |
| POST /courier-settlements; /bank/{id}/match | Remittance and existing-journal matching | finance.manage / finance.reconcile |
| POST /bills; /bills/{id}/pay; /finance/transfer | Payables/settlements/transfers | finance.manage |
| POST /recurring/{id}/confirm | Confirm actual recurring supplier invoice | finance.manage |
| POST /journals; /journals/{id}/reverse | Balanced manual posting/reversal | accounting.post |
| POST /journal-drafts; /drafts/{id}/edit, /post | Editable unposted journals | accounting.post |
| POST /periods; /periods/{id}/close | Create/close accounting period | accounting.close |
| POST /documents; GET /documents/{id} | Private supporting document upload/download | Source-specific permissions |
| POST /integrations/{id}/reconcile, /retry, /credentials | Read-only Woo maintenance | integrations.manage |
| POST /webhooks/woocommerce/{connection} | Signed Woo webhook ingress | Raw-body HMAC, throttle, deduplication |
| GET /reports/{report}/export?format=csv\|xlsx\|pdf | Report download with supported filters | reports.export |
| GET /api/v1/products | Read-only catalog API | bearer `catalog.read` |
| GET /api/v1/sales | Read-only sales API | bearer `sales.read` |
| GET /api/v1/reports/{report} | Read-only report API | bearer `reports.read` |

## Example: receipt

```json
{
  "operation_key": "76c64ccc-1cfa-42f7-a123-43279755ce09",
  "reference": "RC-2026-001",
  "received_on": "2026-10-14",
  "lines": [{ "purchase_line_id": 42, "quantity": "10" }]
}
```

Dispatch uses `sale_line_id`; financial credit uses `fulfillment_line_id`; physical return uses `return_line_id`. Each must belong to the specified document. Cash/bank payments include `account_id`, `amount`, `paid_on`, `method`, `reference` and the operation key. No request may directly assign inventory balances, journal totals, fiscal success or historical movement values.

WooCommerce external order/product IDs are unique per connection and resource type. Shadow records preserve source modification timestamps. Webhooks fetch authoritative current records before applying observations. Deleted source records preserve local history and never reverse ERP stock/accounting. Woo credentials are encrypted using the application key, which must be retained in protected backups.

Bearer tokens are created and revoked through the authenticated `/api-tokens` page. The secret is returned only at creation; persistent storage uses a SHA-256 hash. Send the token in `Authorization: Bearer <token>`. Revoked, expired, or deactivated-user tokens return 401, and an otherwise valid token without the endpoint's scope returns 403.
