# Advanced finance, imports and API workflows

## Customer deposits

Record a prepayment in **Customer deposits** with the cash or bank account that received it. The system debits that account and credits the customer-deposit liability account (2300); it does not recognize revenue. Allocate the balance only to a sale whose receivable has been recognized. A refund can use only the remaining unallocated balance and produces the reverse cash/bank movement.

Before using deposits live, the accountant and fiscal provider must confirm whether local rules require an invoice, fiscal receipt, correction, or different tax treatment at receipt time. The ERP ledger record by itself is not a fiscal document.

## Supplier credits and late landed costs

Supplier credits can reference a supplier bill, purchase order, or supplier-return claim. When connected to a bill, the credit is automatically allocated and reduces the amount that can subsequently be paid. The service checks supplier ownership, document caps, VAT recovery eligibility, and immutable posting keys.

For an invoice received after stock has been received, open the purchase and use **Shto kosto pas pranimit**. Pick freight, customs, clearance, insurance, domestic transport, or another category and allocate by value, quantity, weight, or volume. The adjustment allocates the part still on hand to inventory and the part already sold to cost of goods sold; the split uses the current weighted-average stock balance. The source is cash, bank, or supplier payable. Keep the supporting invoice attached to the purchase and record the reason.

## Promotions and delivery refunds

Promotions support fixed or percentage discounts, validity dates, minimum order amount, a total redemption cap, and a per-customer limit. The sale records both the promotion and its line-level allocation before it reserves stock. Staff can enter the code on the sales form; the POS quotation accepts the same code through its request data.

A delivery credit is tied to a recognized fulfilment and cannot exceed the recognized delivery charge. A cash or bank delivery refund is separately capped by both that credit and cash received for the sale, including allocated deposits. This prevents an over-refund when the customer has not paid enough.

## CSV imports and bank matching

The **Imports** page provides a downloadable CSV header template and a first-20-row preview for products, customers, suppliers, opening balances, historical journals, and bank statements. The service rejects malformed headers, duplicate identities, invalid decimal/date formats, unbalanced journal groups, duplicate bank references, and a file whose content hash was already imported. Each batch is recorded with its filename, hash, user, and row count.

Bank CSV rows become unmatched bank transactions for the existing reconciliation screen. This is a controlled file import and matching workflow; automatic bank feeds require bank-specific credentials and an approved connection.

## Report downloads

Every report export supports CSV, XLSX, and PDF. The XLSX export is a native spreadsheet with a header row, filters, frozen headings, numeric cells, and formula-injection protection. The PDF is a compact print-ready tabular export. The reports remain management/accounting aids; statutory submissions need accountant review.

## API tokens

Owners create scoped bearer tokens in **API tokens**. The raw token is shown only once. The database stores only a SHA-256 hash, a short display prefix, expiry, last-use timestamp, and revocation state. Revoke a token from the same page as soon as an integration is retired.

Available scopes and endpoints are:

| Scope | Endpoint |
|---|---|
| `catalog.read` | `GET /api/v1/products` |
| `sales.read` | `GET /api/v1/sales` |
| `reports.read` | `GET /api/v1/reports/{report}` |

Send `Authorization: Bearer <token>` over HTTPS. Token requests are rejected after expiry, revocation, or user deactivation. These endpoints are deliberately read-only.
