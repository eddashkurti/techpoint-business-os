# TechPoint Business OS — integrations and hardware discovery

Research date: 14 October 2026. Status: proposal; no integration, device test, credential provisioning, DNS change, or production modification has been performed.

## Inspected environment

The supplied workspace contains `work/` and `outputs/` and is not a Git repository. No application code, database, server configuration, or project-specific AGENTS.md was found during the workspace inspection. The host reports Windows NT 10.0.26200.0. Node, npm, Python, Git, and ripgrep are available on PATH. PHP, Composer, Docker, and Claude Code were not found on PATH. This does not establish that they are absent elsewhere on the computer. No Contabo account, server connection, or WooCommerce administration access has been supplied.

A read-only request to [the public TechPoint storefront](https://techpoint-ks.com/) returned a readable Albanian product catalogue. That is a single availability observation, not an uptime, checkout, security, inventory, or authenticated REST API test. Existing platform details otherwise come from the user's brief.

## Verified WooCommerce capabilities

[WooCommerce's current v3 documentation](https://developer.woocommerce.com/docs/apis/rest-api/v3/) recommends `/wp-json/wc/v3/` and documents products, variations, orders, order refunds, and webhooks. [Authentication documentation](https://developer.woocommerce.com/docs/apis/rest-api/authentication/) supports Read-only keys, with permissions also constrained by the associated WordPress user's capabilities. Those public capabilities have not been tested against TechPoint's installed WooCommerce version, custom plugins, security configuration, or hosting.

[The v3 webhook documentation](https://developer.woocommerce.com/docs/apis/rest-api/v3/webhooks) specifies a base64 HMAC-SHA256 signature in `X-WC-Webhook-Signature`, delivery identifiers, and disabling a webhook after five consecutive failed deliveries. Scheduled reconciliation is therefore required even when webhooks are enabled. Webhook creation changes the live site's configuration and will be proposed separately after development tests and explicit authorization; initial development uses an isolated fixture/test store, followed by authorized read-only polling.

## Proposed synchronization contract

| Concern | Proposed rule |
| --- | --- |
| Initial ownership | WooCommerce retains live online inventory, orders, prices, and customer checkout. ERP imports into a clearly marked shadow dataset; shadow data is not the operational stock or accounting ledger. |
| Product identity | Unique `(connection_id, product_id, variation_id)` maps to one ERP product. SKU is an additional business key, not the sole external identity. Missing, reused, or conflicting SKUs require review. Bundles require an explicit component mapping. |
| Order identity | Unique `(connection_id, external_order_id)`; preserve external line-item IDs and source modification timestamps. Order, payment, fulfillment, and delivery statuses are mapped independently. |
| Refund identity | Unique `(connection_id, external_order_id, external_refund_id)`. A financial refund never automatically implies a physical restock. |
| Events | Validate signature over raw bytes with constant-time comparison before durable inbox storage. Bound request size, redact secrets, authenticate the connection, and reject invalid payloads. |
| Idempotency | Delivery ID deduplication plus business-operation uniqueness. Different events about the same receipt/refund cannot create the same inventory or accounting effect twice. |
| Ordering | Store source revision and payload hash. Re-fetch current state when events are stale, contradictory, or arrive out of order. Periodically reconcile all relevant records, including refunds. |
| Recovery | Bounded retries with backoff, durable failed-job records, monitored last-success timestamp, and authorized manual reprocessing. Acknowledge receipt only after inbox persistence. |
| Privacy | Import customer information only for an approved operational purpose. Restrict and expire raw payloads under the agreed retention policy. |

An online order's creation alone will not create recognized revenue or a stock deduction in the ERP. After an approved migration boundary, configured operational events will reserve, dispatch, release, or receive goods. Do not replay historical sales against an opening stock balance that already incorporates them.

Before ERP becomes inventory authority: back up the site and ERP; approve SKU/variation/component mappings; count physical stock; reconcile reservations, open orders, refunds, and courier balances; record a synchronization watermark; drain or pause conflicting writers during the agreed cutover window; activate a single-writer policy; replay later events exactly once; verify balances; and monitor discrepancies. Rollback must preserve transactions after cutover and reconcile authority before resuming writes. Restoring an old database alone would lose new transactions and is not an adequate rollback.

## Hardware evidence and limits

| Device/operation | Confirmed public evidence | Remaining verification |
| --- | --- | --- |
| XPrinter XP-Q80CS | Manufacturer documentation located for the related [XP-Q80C](https://www.xprintertech.com/xp-q80c): 80 mm media, ESC/POS, Windows/Linux/macOS/Android drivers, and interface variants including USB, serial, LAN, and optional wireless combinations. | This does **not** verify the exact XP-Q80CS variant. Obtain exact device identification, ports, driver version, workstation OS, Albanian character/QR rendering, and certified fiscal provider's written compatibility position. No driver or device has been tested. |
| NETUM NT-2028 | NETUM's [wireless setup manual](https://support.netum.net/hc/en-us/articles/49408930454555-Wireless-Barcode-Scanner-Setup-Manual) explicitly includes NT-2028 variants and documents keyboard/HID and serial connection settings. The current [product listing](https://www.netum.net/products/nt-2028-barcode-scanner) names a Wi-Fi variant. | The user's description of a wireless CCD model is not enough to establish the exact revision. Confirm label/firmware, receiver/interface, symbologies, keyboard layout, terminator, and workstation compatibility before selecting an integration path. |
| Browser printing | The [HTML printing standard](https://html.spec.whatwg.org/multipage/timers-and-user-prompts.html#printing) defines `window.print()` as prompting the user to print. | Normal browser printing provides no portable guarantee of silent printing or successful physical output. A vendor-supported local service or approved kiosk configuration would require separate verification, maintenance, and security review. |

Proposed first scanner path is keyboard/HID entry into a focused barcode field, subject to an actual test. Scans are input; they do not bypass product lookup, price authorization, or server-side stock checks. Prevent duplicate submissions without suppressing intentional repeated scans that increase quantity. Test leading zeroes, EAN/Code128, unknown barcodes, damaged labels, reconnects, and keyboard layout.

Printer acceptance must cover 80 mm layout, `ë`/`ç`, provider-required QR/identifiers, cutter, paper-out/disconnection, reprint marking, and avoiding duplicate fiscal issuance when only the physical print fails. Reprinting an already confirmed document is separate from issuing another fiscal document.

## External dependencies and release limits

Public WooCommerce documentation establishes available integration mechanisms; it does not establish TechPoint-specific access or behavior. Fiscal provider APIs, licensing, offline workflows, and exact printer compatibility remain separate research gates. No external credentials should be pasted into chat or committed to source control. Nothing in this report constitutes a tested production integration.
