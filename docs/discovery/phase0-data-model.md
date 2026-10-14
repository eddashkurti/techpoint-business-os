# TechPoint Business OS — Phase 0 data model and transactional policies

Status: logical proposal for approval, not an implemented schema or verified accounting policy. This document applies whether the approved implementation uses Laravel with MySQL/MariaDB or ERPNext. No application code, migrations, stock changes, fiscal submissions, or production changes have been made for this proposal.

If ERPNext is selected, use its supported native product, purchasing, stock, sales, payment, and ledger documents wherever the fit assessment confirms the required behavior. Integration inboxes, mappings, fiscal requests, and courier reconciliation may need extensions. Do not introduce a second accounting ledger or override native valuation internals to reproduce the tables below. Confirm native precision, valuation, cancellation, permissions, and reporting behavior during the fit assessment.

## 1. Modeling principles

- One business and one central physical warehouse. `sales_channel` describes where a sale originated; it never creates another warehouse or its own stock balance.
- Separate editable drafts from immutable posted facts. Stock movements, posted journal lines, fiscal confirmations, payment postings, and audit records are append-only. Corrections create linked reversals or adjustments with reasons.
- Derive stock, balances, and reports from posted transactions. Cached balances are transactional projections with a reconciliation process; they are not independently editable facts.
- Keep order, payment, fulfillment, delivery, return, and fiscal states separate. A WooCommerce status is an external observation, not automatic proof of payment, revenue, delivery, or fiscal issuance.
- Use EUR as the base reporting currency. Retain original amounts, transaction currency, applied exchange rate, source, effective date, and rounding policy.
- Post operational and accounting effects together when both belong to one business event. Prepare the posting foundation before releasing financially binding sales; Phase 4 expands accounting workflows and reports rather than adding balancing retrospectively.
- Effective-dated accounting, tax, recognition, and synchronization policies require owner/accountant approval. This proposal does not assume VAT registration, input-VAT recoverability, fiscal applicability, or a statutory reporting framework.

## 2. Entity and relationship proposal

The identifiers below are logical concepts, not a commitment to custom table names. All records carry an internal ID; drafts and master data also carry a version for optimistic concurrency. Posted facts carry business time, recording time, posting user/service, source event, and supporting document references.

| Domain | Principal entities | Relationships and responsibilities |
|---|---|---|
| Business configuration | `business`, `warehouse`, `business_policy`, `currency`, `exchange_rate`, `tax_code`, `accounting_period` | One business has one active central warehouse. Policies and tax codes have effective dates; transactions snapshot the policy/rate applied. Periods have open/closed state and approved close events. |
| Access and evidence | `user`, `role`, `permission`, `user_role`, `role_permission`, `access_scope`, `audit_event`, `attachment` | Many-to-many role grants, explicit resource scopes, private attachments, and immutable audit events. Integration secrets use protected secret storage, not general settings or attachment fields. |
| Product catalog | `product`, `category`, `product_barcode`, `unit`, `unit_conversion`, `product_supplier`, `product_price` | Unique internal SKU; multiple barcodes when appropriate; category hierarchy; supplier links; exact unit conversions. Store customs/origin metadata without inferring legal classification. Product images and documents reference private or approved public assets. |
| Shared counterparties | `party`, `party_role`, `party_address` | A party may be customer, supplier, or courier; transactions snapshot relevant names/address/tax identifiers. Retain only authorized customer details needed for operations and accounting. |
| Inventory | `stock_movement`, `stock_balance`, `stock_reservation`, `stock_count`, `stock_count_line`, `inventory_cost_state` | Every stock effect is a signed movement with product, warehouse, condition, quantity, value, source, and reason. Reservations allocate availability without altering physical stock. A count creates approved difference movements. Current balances/cost state are projections. |
| Purchasing and landed costs | `purchase_order`, `purchase_order_line`, `supplier_invoice`, `supplier_invoice_line`, `import_shipment`, `shipment_line`, `landed_cost_component`, `landed_cost_allocation`, `purchase_receipt`, `purchase_receipt_line` | A shipment can combine invoices/orders. A purchase order can have multiple partial receipts. Costs belong to a shipment or identified receipt; allocations link each cost component to eligible product lines. Invoices and receipts are distinct events. |
| Sales | `sales_channel`, `sales_order`, `sales_order_line`, `sales_adjustment`, `sales_invoice`, `sales_invoice_line`, `fulfillment`, `fulfillment_line` | One order belongs to one originating channel, with multiple fulfillments and invoices where required. Lines snapshot SKU/name, unit, price, tax, discount, and category/reporting dimensions. Commercial changes do not silently change already posted invoices. |
| Money and balances | `financial_account`, `payment_method`, `payment`, `payment_allocation`, `bank_transaction`, `expense_document`, `expense_line`, `recurring_expense_template`, `account_transfer` | A payment may cover multiple invoices, and an invoice may have partial payments. Cash/bank movements are separate from income/expense recognition. Recurring templates create unique drafts for their period; transfers never become sales or expenses. |
| Returns and delivery | `return_authorization`, `return_line`, `return_inspection`, `refund`, `refund_allocation`, `delivery`, `delivery_event`, `courier_statement`, `courier_statement_line`, `courier_settlement`, `reconciliation_match` | Refunds reference the original commercial document; physical returns reference fulfillment lines. Delivery events are observations. Courier lines link deliveries, amounts collected, deductions, and remittances without conflating them. |
| Accounting | `account`, `journal_draft`, `journal_draft_line`, `posted_journal`, `posted_journal_line`, `posting_rule`, `reversal_reference`, `period_close_event`, `budget`, `budget_line` | Configurable chart of accounts. Each posted journal has multiple balanced lines, each with source references and relevant party/channel/product dimensions. Budgets reference accounts and periods. Reversal journals reference originals; posted originals remain unchanged. |
| WooCommerce | `integration_connection`, `external_object_mapping`, `integration_inbox`, `integration_job`, `sync_checkpoint`, `external_snapshot`, `sync_exception`, `reconciliation_run` | Unique mapping per connection/object type/external ID; order-item and refund-item mappings preserve line identity. Durable inbound events, read-only snapshots, retries, checkpoints, and reconciliation exceptions are independent of stock/accounting posting. |
| Fiscal integration | `fiscal_provider_configuration`, `fiscal_request`, `fiscal_request_attempt`, `fiscal_confirmation`, `fiscal_document_reference`, `integration_outbox`, `fiscal_reconciliation_case` | A immutable request version contains the commercial/tax snapshot and semantic idempotency key. Attempts record transport outcomes. Only a verified successful provider response/query creates an issuance confirmation. Credentials and sensitive provider payloads are encrypted and access-controlled. |

Core relationship paths:

1. Supplier → purchase order → shipment/invoice/cost allocations → confirmed purchase receipt → stock movement → posting journal.
2. Channel → sales order → reservation → fulfillment → stock movement/cost snapshot → sales recognition and accounting journal at the approved recognition event.
3. Sales invoice → payment allocation → cash/bank/courier clearing; a payment can exist before a revenue event.
4. Original sale/fulfillment → return inspection → appropriate stock movement; original invoice → credit/refund document → money and accounting effects. These are separate branches.
5. Source business event → posted journal and/or stock movement → integration outbox → external confirmation. External network work does not run inside the database transaction.

## 3. Precision, units, time, and database constraints

### Exact values

Proposed custom-schema precisions are quantities `DECIMAL(20,6)`, unit prices/costs `DECIMAL(24,8)`, conversion and exchange rates `DECIMAL(24,12)`, document totals/base journal amounts `DECIMAL(20,2)`, and retained inventory calculation values `DECIMAL(24,8)`. Validate ranges against actual units and currencies before migration. Currency minor-unit precision is configurable; EUR documents and ledger postings round to cents. Use decimal arithmetic throughout backend, jobs, imports, and calculation tests. Browser form values and API money/rates should be decimal strings, avoiding binary floating-point serialization.

Retain both precise allocation results and the posted rounded amount. Define one rounding mode, when it applies, and deterministic residual allocation. For each cost component, allocate rounding residuals to a deterministically selected eligible line so rounded allocations equal the source amount exactly. Never use an unexplained balancing plug. Explicit rounding accounts handle document/ledger residuals where approved.

Store quantity in a product base unit and retain the original entered quantity/unit/conversion snapshot. Restrict indivisible products to integral base quantities. Product purchase prices are reference/history values; receipt cost and dispatch cost snapshots determine valuation and COGS. Changing a supplier price or current selling price never rewrites historical cost or revenue.

### Time

Store instants in UTC with sufficient precision. Also store the approved business accounting date, which may differ from the ingestion/recording date. Apply the configured business timezone to period filters and display; tests cover local midnight and daylight-saving transitions.

The brief requests `Europe/Pristina`. On 2026-10-14, the available Windows/.NET runtime rejected that identifier and accepted `Europe/Belgrade`. This is an observed runtime limitation, not a claim that every timezone database has been verified. Confirm the supported timezone that represents Kosovo business time, persist it as configuration, and record the approved choice. Do not silently adopt the operator's Europe/Warsaw display timezone as the company's accounting timezone.

### Constraints

- Unique business SKU; barcode uniqueness according to the approved barcode model; unique purchase/order/document numbers within defined scopes.
- Unique source-effect keys for posted inventory, journals, payments, refunds, courier settlements, and integration commands. One source can have several intentional effects, so uniqueness includes effect type and line/version where needed.
- Unique `(connection, object_type, external_id)` mappings and unique provider document references within provider/account/environment scopes.
- Foreign keys protect relationships. Financial facts cannot cascade-delete when a product, user, party, connection, or master record is deactivated.
- Posted journal lines contain one positive debit or credit, not both; total base debits equal total base credits. Journal drafts are stored separately from immutable posted records in a custom implementation.
- Database row checks and uniqueness supplement server validation. MySQL/MariaDB row checks do not themselves prove that the aggregate journal lines balance: the transactional posting service validates line sums and header totals before commit, with independent reconciliation checks.
- Attachments have content hashes and metadata, private storage paths, access checks, and retention policies. A file hash alone is not proof that two commercial invoices are duplicates.

## 4. Inventory and landed-cost policy

### One warehouse and explicit stock conditions

Use one central warehouse with condition buckets such as `sellable`, `quarantine`, and `damaged`. These are product conditions, not sales-channel warehouses. Transfers between conditions have linked balanced quantity/value movements. Stock never becomes sellable merely because a customer requested a refund.

`available_to_promise = sellable_on_hand − active_reservations`. Physical on-hand includes the relevant central condition buckets; reserved quantity is shown separately. Company-owned outbound goods awaiting delivery are tracked through fulfillment lines and an in-transit inventory account/subledger when the recognition policy requires it; they are not another sales-channel warehouse.

Default proposal: disallow negative sellable stock. A backorder has no physical deduction and cannot reserve nonexistent stock unless a separate, explicitly labeled backorder policy is approved. A physical count posts approved differences with mandatory reasons; it never overwrites the balance column.

### Moving weighted-average cost

For a cost-ready receipt of quantity `q` and capitalizable landed value `L`, with previous central inventory quantity `Q` and value `V`, the new average is `(V + L) / (Q + q)`. Confirmed dispatch captures its cost from the serialized cost state and removes its quantity/value. Selling price does not enter this calculation. When the last units leave a valuation pool, remove its remaining recorded value so a zero quantity cannot retain unexplained residual inventory value.

The final approved valuation-pool policy must specify whether all central conditions share one average or separately tracked condition values are retained. Recommended operational behavior: normal sellable stock uses moving average; returns and quarantine retain their source cost until accepted into sellable stock, then enter that pool at that value. Damage moves the current carrying value into quarantine/damaged tracking; impairment or write-off is a separate approved accounting event. Reconcile the sum of these values, plus company-owned outbound inventory, to inventory ledger control accounts.

A return accepted for resale enters at the original dispatched carrying cost, capped by the unreturned quantity and related source cost. It then participates in the current weighted average. Unknown-source stock requires an approved adjustment and explicit cost basis. A refund without physical receipt has no restocking effect. Supplier returns reference receipt/invoice quantities; differences between inventory carrying value and supplier credit have explicit accountant-approved postings.

### Landed cost

Each cost component has an amount/currency/rate, source document, category, capitalizable share, tax treatment, allocation basis, eligible lines, and approval state. The supported bases are value, quantity, weight, volume, and explicit documented allocation. Do not mix unlike quantities: allocating a carton fee by pieces requires validated conversions; weight/volume allocation requires complete measurements. Zero denominators or missing data block confirmation.

Convert each applicable component to EUR using its recorded rate and accounting date. Allocate capitalizable purchase, freight, insurance, customs, clearance, and attributable transport only according to the approved accounting policy. Customer delivery/postage, advertising, and routine operating expenses have their own accounting treatment and are not automatically added to product cost.

Import VAT has configurable recoverability from 0% to 100% where applicable. The recoverable share posts to the appropriate tax receivable/control account and is excluded from landed inventory cost. The nonrecoverable share is capitalized or expensed according to the approved policy. Keep provisional or undetermined VAT treatment visibly unresolved; do not presume deductibility or legal tax rules.

Bounded first-release proposal: finalize landed-cost allocation before posting a saleable receipt. Physical arrival can be recorded in an unposted receipt/inspection draft; the UI must clearly state that those goods are awaiting confirmation and unavailable for sale. If the business needs operational receipt before all import costs are known, explicitly extend Phase 2 with provisional-cost posting and approved subsequent inventory/COGS/in-transit adjustments. Do not silently mutate historical receipt values or assume every later import cost belongs to the remaining stock. This workflow decision requires business and accountant approval.

Posting an invoice and posting a receipt are distinct. Product purchases affect inventory and supplier liability through the chosen receipt/invoice clearing policy; they do not also become an operating expense. Purchase orders alone change neither inventory nor profit. A partial receipt changes only its confirmed quantities, once.

## 5. Sales, payment, return, and courier transitions

Suggested state sets are independent and may need refinement after legal/provider research:

- Order: draft, accepted, cancelled, completed; immutable state events explain transitions.
- Payment projection: unpaid, partially paid, paid, partially refunded, refunded. Include disputes/chargebacks if a supported payment method needs them; retain all underlying transactions.
- Fulfillment: unallocated, reserved, partially dispatched, dispatched, partially returned, returned.
- Delivery: pending, in transit, delivered, failed, returning, returned, with courier evidence and timestamps.
- Fiscal: not required, required/pending, queued, submitting, outcome unknown, issued, rejected, intervention required. Whether a sale is not required is governed by the approved applicability policy, not a casual operator choice.

| Event | Physical stock | Financial/accounting effect |
|---|---|---|
| Order accepted | Reserve the authorized quantity atomically; do not reduce on-hand | No revenue solely from order creation; a deposit/payment is a separate cash/liability/receivable event under the approved policy. |
| Order cancelled before dispatch | Release remaining reservations once | Reverse/refund only the recorded payments or posted documents that actually exist. |
| Dispatch confirmed | Consume matching reservations and deduct confirmed physical quantity once; snapshot carrying cost | If control transfers on dispatch, recognize the relevant revenue and COGS then. If control transfers on delivery, move cost to in-transit inventory and defer revenue/COGS until confirmed delivery. |
| Delivery confirmed | No second physical stock deduction | Recognize deferred revenue/COGS once where that is the approved policy; record COD collection as receivable from the courier/clearing account where applicable. |
| Delivery failed | No automatic restock | Retain goods in transit/returning ownership tracking; delivery/return fees are recorded separately. Reverse recognized sale only through approved linked documents, according to policy. |
| Returned goods inspected | Restock only inspected, physically received quantities; quarantine/damage quantities separately | Reverse relevant sale/COGS at original source amounts through approved credit/return records; keep freight and fee effects explicit. |
| Refund sent | No stock effect from the money event | Settle the credit/customer balance once. Whether a credit is required is governed by the commercial/accounting workflow; avoid recognizing a revenue reversal twice. |
| Courier remits settlement | No stock effect | Clear courier receivable for the gross collected amount; record cash received and fees/deductions separately, with unresolved differences as exceptions. |

The revenue transfer-of-control trigger remains an accountant-approved decision, especially for cash-on-delivery. Do not hardcode payment, dispatch, or WooCommerce `completed` as a universal revenue trigger. Partial fulfillment, credit, payment, return, and settlement records carry their own quantities/amounts and cannot exceed the linked eligible remainder without an explicit reviewed exception.

Channel attribution is copied to recognized sale/credit documents and reporting dimensions. An imported Woo order and a manual correction to that same order preserve one canonical order identity; the user cannot duplicate it by importing again.

## 6. Transaction, locking, and correction strategy

For each posting command in a custom implementation:

1. Authenticate and authorize the actor; validate command fields and a unique idempotency key plus request hash.
2. Begin a database transaction. Acquire locks for the source record/version, relevant open period, stock/cost rows, reservations, and affected payable/receivable balances. Lock product rows in a deterministic order to reduce deadlocks.
3. Recheck statuses, unprocessed source keys, remaining quantities/amounts, availability, effective policy, and period status under the locks.
4. Calculate exact quantities/costs/tax/amounts; prepare immutable stock records, balanced journal lines, document snapshots, and related payment allocations as applicable.
5. Insert the source event and its effects; update the transactional projections; add durable integration-outbox commands in the same transaction.
6. Store the idempotent result and commit. Dispatch network work only after commit through the outbox processor.

Repeated commands with the same key and payload return the original outcome. Reuse with a different payload returns a conflict. Database uniqueness provides the final duplicate barrier. On deadlock/serialization failure, retry the entire command a bounded number of times with jitter, retaining the same idempotency key. Do not retry only the final insert or bypass a failed validation.

Use a supported transaction isolation strategy explicitly tested with the chosen database/framework. In a custom moving-average implementation, serialize stock/cost changes with row locks; do not rely on a stale UI stock display or a process-local mutex. Use version checks for concurrent draft edits. Closing a period locks/checks the same period record that posting checks, preventing a race between closing and posting.

ERPNext implementations must use its supported document submission, stock ledger, valuation, and cancellation mechanisms and verify their transactional behavior through acceptance tests. They must not write directly to native stock or general-ledger tables using the custom sequence above.

Corrections reference original effects and require a reason, permissions, and approved accounting date. Reverse only the remaining reversible portion. Corrections in a closed period post through the permitted current-period workflow or an explicitly authorized reopen process; originals remain auditable. Posted facts cannot be hard-deleted or silently soft-deleted. Restrict application database privileges on immutable tables where practical; migrations use a separate privileged identity. Administrative database access remains a monitored, restricted operational privilege.

## 7. WooCommerce authority, shadow imports, and cutover

Initial mode is read-only/shadow synchronization. WooCommerce remains authoritative for its live catalog/order/stock operation. Import products, variation IDs, SKUs, orders/items, refunds, customer fields permitted for use, statuses, and stock observations into mappings/snapshots and reviewed ERP drafts. Imported historical data does not create live ERP stock movements, opening balances, cash, or posted revenue automatically. Label shadow reports and exceptions so they cannot be mistaken for posted financial statements.

Webhook handling validates signatures using the actual supported mechanism, persists the accepted event before acknowledging it, and records a connection-scoped delivery/event identifier when available. Treat delivery as at-least-once and potentially out of order. Resource identity and canonical mapping prevent duplicate orders even when the sender supplies no stable event ID. A repeated payload/hash is useful evidence, not the only business-effect idempotency control. Processing uses resource versions/timestamps where reliable and can read the latest authoritative resource through the read-only API before applying an older event.

Keep Woo order status and payment/status evidence in external fields. Map into ERP transitions using an explicit, versioned rule set; unmapped/contradictory events create reconciliation exceptions. A webhook reporting a refund must not independently restock goods. Scheduled reconciliation compares canonical resources, counts/amounts, unprocessed refunds, and recent changes; record discrepancies and last successful checkpoints. Manual reprocessing uses the same keys and produces no duplicate stock/journal/payment effect.

Before any authority change: back up the website and ERP, test staging, map every relevant product/variation/SKU, establish opening-stock/accounting dates, reconcile physical stock with both systems, define treatment of historical and still-open orders/returns, drain pending events, choose the one stock writer, and obtain explicit cutover approval. If ERP owns stock later, Woo becomes a controlled projection for stock updates, with one authorized writer and loop prevention. Rollback must account for any ERP sales/stock movements after cutover; restoring an old Woo quantity blindly is unsafe. Price and catalog ownership require separate decisions.

## 8. Fiscal outbox and unknown-outcome handling

Create a provider-independent adapter contract for request validation, submission, status lookup/reconciliation, and supported credit/cancellation operations. The concrete provider/API/offline/hardware rules remain unverified until official/provider research confirms them.

A required fiscal sale creates a frozen request and durable outbox command in the same transaction as its authorized internal state. Use a semantic uniqueness key covering the business, provider account/environment, sale/document version, and operation type. Bind every confirmed provider document to exactly one internal request; persist the provider identifier and validation evidence.

An attempt timeout, process crash after sending, or lost response enters `outcome_unknown`. It is not proof of rejection. If the provider supports an enforced idempotency key, retries reuse the identical key and immutable payload under the documented provider contract. Otherwise query by stable supported reference; if issuance cannot be determined, stop automatic submission retries and open a reconciliation case for authorized resolution. Local locks/outbox uniqueness alone cannot prevent duplicate external receipts when a non-idempotent provider has received a request but lost the response.

Only a verified successful provider issuance response or status lookup creates `issued`. A queued command, HTTP success without the required issuance evidence, PDF, printer output, or local receipt number is insufficient. Credit/cancellation requests reference the confirmed original and use their own distinct operation keys. Do not delete or overwrite a confirmed fiscal reference.

Required fiscalization gates the normal legally completed sale/receipt workflow. A pending payment or business event can be recorded truthfully while issuance is unresolved, but operators cannot label the sale fiscally complete or print a misleading fiscal receipt. The exact allowed behavior during outages must follow verified legal/provider rules; until those rules and access are confirmed, issuance remains blocked/pending implementation. Record failures, attempt metadata, next action, and reconciliation status without leaking credentials or unnecessary customer data.

## 9. Accounting, access, and reports

### Accounting controls

Keep inventory purchases, recognized COGS, operating expenses, VAT, customer delivery revenue, delivery expense, financing, account transfers, and settlement cash in separate accounts/flows. Link AP/AR control lines to parties, documents, allocations, and original currencies. FX settlement differences become explicit entries under the approved policy. Trial balance, subledger totals, inventory value, and financial-account reconciliation must agree with posted journals. A cash receipt is not revenue solely because it increases a bank balance.

Statutory tax reports, tax treatment, financial statement formats, fiscal applicability, and closing/reversal rules need professional validation. The system should label unvalidated reports and prevent presenting them as approved filing outputs.

### Accountant permissions

Grant separate permissions for viewing financial documents, viewing posted ledger, viewing permitted fiscal evidence, preparing reconciliation, creating drafts, posting journals, reversing journals, reopening periods, exporting, approving costs, and reviewing supporting documents. The accountant receives only the grants and account/document/channel scopes approved by the owner. Do not automatically grant stock adjustments, refunds, fiscal submission/cancellation, integrations, user administration, or secret access. Owner authority does not allow silent edits to posted facts.

Enforce permissions and scopes on the server for listing, search, object access, reports, queued exports, attachment downloads, and API endpoints. An export must recheck current access before generation and download. Record financial edits, posts/reversals, stock adjustments, role changes, exports, and fiscal actions in the audit trail. UI button visibility is not an authorization control.

### Report definitions for sign-off

| Measure | Proposed definition / source |
|---|---|
| Net sales revenue | Posted recognized product revenue plus separately identified customer delivery/service revenue, less posted discounts/credits/returns, excluding VAT. Show product and delivery components separately. |
| Gross product profit | Net recognized product revenue less net recognized product COGS; state whether a gross-profit view includes delivery/service margins. |
| Operating profit | Recognized operating revenue minus COGS and operating expenses, including advertising and domestic delivery expense. Depreciation and other categories follow the approved chart/accounting policy. |
| Net profit | Operating result plus/minus recognized nonoperating/financing items and income-tax expense where applicable. Label as provisional if those entries or accountant validation are incomplete. |
| Cash flow | Posted movements in cash/bank accounts, with internal transfers eliminated in consolidated totals; distinguish operating/investing/financing classification when approved. |
| AR/AP and courier balances | Posted subledger open items minus linked payments, credits, and reconciled settlements, with currency and ageing rules. Courier COD receivables are distinct from money already in the bank. |
| Inventory asset value | Carrying value of company-owned central sellable/quarantine/damaged stock plus company-owned outbound inventory, net of posted impairment; reconcile to control accounts. Reserved units remain included. |
| Order counts and AOV | Report created, accepted, dispatched, and delivered counts separately. AOV uses positive sale-document consideration for the stated cohort excluding VAT/credit documents; disclose treatment of customer delivery charges. Do not mix historical returns with an unexplained current-period order denominator. |
| Return/refund rate | A cohort measure tied to original delivered units/orders, with the cohort window and observation cutoff shown. Period return/refund volume is a separate measure. |
| Product margin | Actual source COGS and directly attributable expenses where recorded. Any allocated advertising/overhead uses a declared allocation method and is labeled an allocated contribution/net-margin estimate. Product totals do not prove total statutory net profit. |
| Turnover and slow stock | Turnover uses recognized COGS divided by consistently measured average inventory value over the same period; slow/dead-stock age thresholds are configurable and disclosed. |

Use accounting date and approved timezone for financial periods. Retain historical reporting dimensions so renaming/reclassifying a product does not silently rewrite previously reported figures. Exports include filters, date range, policy/version, generated-at timestamp, currency, and reconciliation totals. Reports display only authorized data. Dashboard numbers are derived from the same definitions and posted records; shadow imports remain explicitly labeled.

## 10. Acceptance coverage by phase

| Phase | Minimum evidence before completion |
|---|---|
| 0 — Discovery | Approved logical model, timezone/policy decisions or explicit blockers, ERPNext/custom fit assessment, fiscal research limitations, first-release scope, and risk/approval gates. No implementation success claim. |
| 1 — Foundation | Server-side role/scope denial tests, authenticated sessions, logging/redaction, decimal/time handling design, private-document access, queue/outbox foundations, development backup/restore evidence, and database migration approach. |
| 2 — Inventory/purchasing | Product → order → allocated landed cost → confirmed partial receipt; replay creates no duplicate movement; concurrent receipts/dispatches serialize average cost; sums equal source costs; recoverable VAT excluded; invalid basis fails; stock count reversals; no negative stock; condition/return valuation. |
| 3 — Sales/finance | Concurrent reservation cannot oversell; cancellation releases once; dispatch/delivery do not deduct twice; deposit is not premature revenue; partial payment/return/refund bounds; refund without goods does not restock; supplier purchase is not expensed twice; courier settlement fees and cash reconcile. |
| 4 — Full accounting | Balanced postings, immutable journals, partial linked reversals, period-close/posting race tests, currency/rounding/FX cases, GL/subledger/inventory reconciliation, accountant scopes/exports, accountant-approved P&L and report definitions. |
| 5 — Integrations/POS | Woo replay/out-of-order/refund events create no duplicate records/effects; read-only mode makes no Woo writes; retries/manual reprocessing reconcile; fiscal timeout-after-provider-issue enters unknown outcome; lookup/idempotent retry creates one confirmed document; non-idempotent unknown outcome blocks resend; printer/scanner and provider tests against actual supported environments. |
| 6 — Reporting | Filter/timezone/period definitions reconcile to transaction and ledger totals; CSV/Excel exports reproduce totals; product allocation assumptions disclosed; optional AI access/egress controls validated before use. |
| 7 — Production | Restore off-server backup on a clean environment; import/opening balances and physical/Woo stock reconcile; end-to-end partial/failed/returned COD sale; live website remains operational; approved cutover/rollback rehearsed without lost or duplicated transactions. |

Fiscal issuance, cancellation/refund legality, outage behavior, certification, API authorization, printer compatibility, and tax filings remain external-dependency acceptance gates until validated. Foundation and nonproduction inventory work can proceed after Phase 1 approval; production sale/fiscal workflows cannot be approved by substituting simulations for these gates.

## 11. Questions that require decisions

1. Is TechPoint VAT registered, which purchases/import VAT are recoverable, and which accountant will approve the chart of accounts, tax treatment, recognition, and report definitions?
2. For each channel, when does control transfer: dispatch, delivered acceptance, or another documented event? How are COD collection and courier remittance evidenced?
3. Can saleable receipts wait for finalized landed costs, or must the first release support provisional receipts and later approved cost adjustments?
4. Are units exclusively pieces, or are cartons/packs/fractional goods required? Are weight/volume data available for each chosen import allocation basis?
5. What are the opening-stock/accounting date, initial valuation evidence, opening AR/AP/courier/cash balances, and historical import scope?
6. Which system owns catalog, prices, and stock at each stage; how will open Woo orders/refunds/returns be handled at cutover?
7. Which roles may approve stock adjustments, discounts, refund limits, cost allocations, journal posting/reversals, period reopenings, and exports? What exact accountant scopes are intended?
8. Which verified fiscal provider supports the required channels, idempotency/status lookup, credit/cancellation, offline rules, certification/access, and planned printer? Which questions require ATK/provider confirmation?
9. Which courier/payment exports or APIs exist, and what identifiers connect parcels, orders, collections, fee deductions, and settlements?
10. Which recognized runtime timezone will be approved for Kosovo business reporting in place of, or as supported resolution of, the requested `Europe/Pristina` identifier?

Approval of the Phase 0 architecture and these explicit policy assumptions is required before Phase 1 implementation begins.
