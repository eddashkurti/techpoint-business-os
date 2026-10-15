# TechPoint Business OS

A private Laravel application for TechPoint Kosova. One warehouse serves all sales channels. Financial arithmetic uses decimal strings and Brick Math; stock, journals, payments and audit records have database protections against silent modification.

## Run locally on this computer

Ubuntu WSL, Docker, the application dependencies, MySQL 8.4, a separate test database, frontend assets and an owner account are already installed. Your SQL Server Express and SSMS installation remain separate.

From this repository, open PowerShell and run:

```powershell
powershell -ExecutionPolicy Bypass -File infra/scripts/start-local.ps1
```

Keep that terminal open. Open `http://localhost:8080` in your browser. The local owner email and generated password are in the ignored file `work/local-access.txt`; never commit or share that file. Public account registration is disabled. Change the password and enable two-factor authentication before business use.

The database starts empty of business transactions. The owner must confirm accounting/VAT/fiscal settings and create an accounting period before posting. No actual VAT registration status or fiscal exemption has been assumed. EUR is the currency. `Europe/Belgrade` is the IANA timezone used for Prishtina because `Europe/Pristina` is not an available IANA identifier in the installed runtime. Application timestamps use UTC; business dates and display use the configured timezone.

## Implemented workflows

- Roles, server-side permissions, owner/accountant/warehouse/sales access, inactive-user controls and immutable audit events.
- Product/category catalog, barcode lookup, packaging conversions, stock reservations, weighted-average stock valuation, condition transfers, counted-stock reconciliation and reasoned adjustments.
- Suppliers, purchase orders, distinct landed-cost allocation bases, recoverable VAT separation, partial confirmed receipts, supporting documents and supplier physical returns.
- Channel-specific sales, partial dispatch, delivery-based or dispatch-based recognition, discounts by permission, cancellations, partial customer payments, financial credits, inspected physical returns and bounded refunds.
- Courier collections and partial remittances with fees and unresolved shortfalls, failed deliveries/returned parcels, bank statement matching and cash/bank transfers.
- Supplier invoices, partial payments, recurring invoice confirmation, balanced journals, editable drafts, reversals and accounting period closing.
- Dashboard, date/channel/product filters, monthly charts, product margins, budget comparisons and CSV exports for Excel/accountant review.
- Private document uploads, an internal 80 mm receipt view and Code 128 product labels, POS product/barcode entry and server-calculated quotations.
- Read-only WooCommerce REST/webhook client, encrypted credentials, duplicate protection, shadow records, queued retry, reconciliation and credential rotation. It never writes WooCommerce stock or creates ERP accounting from imported snapshots.
- Fiscal provider interface, frozen request identifiers, confirmed/unknown/rejected states and a duplicate-issuance guard. The production adapter intentionally remains unconfigured.
- Production Docker/Nginx/MySQL/queue/scheduler configuration, isolated privileged migrations, encrypted remote-backup scripts, archive safety checks and restore procedures.

## Readiness

This is an implemented local release, not a deployed production ERP. No VPS, DNS/TLS configuration, VAT decision, WooCommerce credentials, certified fiscal provider or printer/scanner connection details are available. External issuance, physical hardware operation, live synchronization, production deployment and off-server backup recovery cannot be validated yet. Local browser access was denied by the browser permission policy, so visual/browser end-to-end testing remains outstanding.

Prepayments/deposits, post-receipt landed-cost revaluation, bulk historical-data import, automatic Woo order promotion and stock-ownership cutover are not implemented. Required fiscal operations stay blocked until an authorized adapter confirms the specific operation. Internal printed documents are prominently marked as non-fiscal. See [release status](docs/release-status.md) for the precise boundaries.

## Verify

With the local preview running:

```powershell
powershell -ExecutionPolicy Bypass -File infra/scripts/check-local.ps1
```

The runner uses only `techpoint_testing`, then a uniquely named restore database. It never resets `techpoint`. Do not run raw PHPUnit against a runtime `.env`; use the guarded runner. Node 24 is required for frontend tooling. Generate routes with `php artisan techpoint:routes`, then run `npm ci`, `WAYFINDER_SKIP=1 npm run build` and `npm run types:check` in an environment with PHP available for route generation. `python -m unittest discover -s infra/tests` validates backup archive boundaries. GitHub verification is defined in `.github/workflows/verify.yml`.

## Documentation

- [User and accounting guide](docs/user-guide.md)
- [JSON/REST contract](docs/api.md)
- [Deployment, monitoring, backup and recovery](docs/operations/deployment.md)
- [Calculation rules and integrity](docs/calculations.md)
- [Release status and acceptance gates](docs/release-status.md)
- [Native Linux secret-permission fix](docs/secret-permission-fix.md)
- [Discovery proposal](docs/discovery/TechPoint-Phase-0-Proposal.md)
- [Fiscal research](docs/discovery/phase0-fiscal-research.md)
- [Hosting and cost research](docs/discovery/phase0-stack-hosting.md)

## Hosting and source control

Local development needs no cloud subscription. Online access for staff needs an always-on server; the planned Contabo host can run the application and restricted MySQL together. An additional managed database, AWS, Azure or Firebase account is unnecessary. Verify the actual contract and backup-storage cost before purchase; the advertised VPS price alone is not a confirmed total.

Private GitHub repository: https://github.com/eddashkurti/techpoint-business-os . Source uses Edda's linked Git identity. Git history is not a database backup. `.env`, credentials, fiscal keys, uploads, dependency directories and database dumps are excluded from Git and Docker build contexts. Keep configuration and business data private.
