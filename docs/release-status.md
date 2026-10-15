# Release status — 14 October 2026

The owner authorized implementation without further phase approvals. The original discovery approval gate is superseded by that instruction. Deployment remains dependent on actual infrastructure and business/provider facts; none were supplied.

| Phase | Result | Validation/boundary |
|---|---|---|
| Discovery | Completed | Architecture, fiscal/provider research, hardware and hosting findings recorded with sources |
| Foundation | Implemented locally | Laravel/Vue/MySQL, authentication, server gates, owner and roles |
| Inventory and purchasing | Implemented locally | Exact unit conversions, allocation, partial receipts, stock corrections/counts, physical supplier returns |
| Sales and returns | Implemented locally | Reservations, partial fulfilments, recognition policy, partial recognized-sale payments, credits, physical receipts and refunds |
| Accounting and finance | Implemented locally | Balanced immutable journals, periods, drafts, bills, recurring confirmation, courier and bank reconciliation |
| Analytics | Implemented locally | Ledger-derived metrics, 12-month charts, product margins, budgets and CSV/XLSX/PDF reports |
| Advanced operations | Implemented locally | Deposits, supplier credits, late landed-cost split, promotions, delivery refunds, CSV templates/previews/imports, bank-file import/matching and scoped read-only API tokens |
| WooCommerce | Implemented read-only boundary | Protocol tests; real credentials and live store reconciliation unavailable |
| Fiscal | Interface and safe state machine implemented | No certified provider SDK/API agreement or sandbox; production issuance is unavailable |
| POS/hardware | Browser POS and internal print document implemented | Fiscal completion, silent printing and hardware compatibility remain unverified |
| Operations | Deployment/backup tooling prepared; local nonempty restore verified | No VPS, real HTTPS deployment, off-server destination or disaster recovery rehearsal |
| Acceptance | Automated checks performed | Browser permission denial prevented visual and browser end-to-end verification |

## Deliberate functional boundaries

Payments are limited to recognized revenue minus credits and previous net receipts. Customer deposits post to a liability account, can be allocated only to recognized receivables, and can be refunded only from their remaining balance. Live fiscal treatment of a prepayment still needs confirmation from the accountant and certified provider.

Late invoices/cost changes use a controlled revaluation workflow. It allocates a late cost across received lines and posts the quantity currently on hand to inventory and the sold quantity to cost of goods sold; it never rewrites historical movements. Supplier physical returns create a claim in account 1310 at removed weighted-average inventory value. Supplier credits validate their referenced bill, purchase, or supplier-return claim and reduce the payable only through explicit allocations.

WooCommerce observations stay in shadow tables with external IDs. They do not promote orders into the ERP, reserve stock or post revenue. CSV imports cover controlled product, party, opening-balance, historical-journal and bank-file migration; the inventory ownership cutover remains a separate controlled project that must follow the backed-up reconciliation/rollback plan in discovery documentation.

CSV, native XLSX and PDF exports protect against formula injection. Scoped bearer-token provisioning is available for three read-only endpoints, and bank CSV files can be imported and matched. Automated bank feeds, tax returns, external fiscal issuance, WooCommerce order promotion, hardware-mediated printing, and statutory-report certification remain outside this release. Financial reports require accountant validation for statutory use. Product and channel filters include attributed ledger lines; shared operating expenses are not allocated artificially.

## Facts needed before launch

1. Business/accountant confirmation of VAT registration, recovery rules, recognition policy and fiscal obligations.
2. Existing or purchased VPS access, exact contract cost, ERP DNS, HTTPS certificates and recovery email configuration.
3. Separate encrypted remote-backup destination and protected recovery secrets; complete a real destination restore drill.
4. WooCommerce read-only credentials and separately generated webhook secret; validate mappings against a copied inventory snapshot.
5. Certified fiscal provider agreement, documented integration, credentials/sandbox, licensing/fees and correction/offline rules.
6. Physical scanner/printer connection and provider-supported printing path; perform witnessed hardware and browser acceptance tests.

The existing WooCommerce site has not been altered and its inventory remains authoritative for live online operations.

## Follow-up — 15 October 2026

The pending native Linux secret-permission diagnostic is resolved. The generator now allows MySQL initialization to read its mounted backup password while keeping the host directory and other credential files private. The diagnostic is tracked as a regression check and included in CI. Native Linux production-startup validation also verifies actual backup-account reads. See [the fix and validation scope](secret-permission-fix.md).
