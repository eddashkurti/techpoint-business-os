# TechPoint Business OS — Phase 0 stack and hosting assessment

Research date: **14 October 2026**. This assessment is a proposal. No server purchase, server inspection, application installation, or deployment was performed. A plan advertised on a provider website is not evidence that TechPoint owns an instance with those resources.

## 1. Technology recommendation

**Recommend a Laravel modular monolith under the requirements as written, with one transactional SQL database.** ERPNext deserves serious consideration because its existing purchasing, stock, landed cost, ledger, and correction workflows would ordinarily reduce development effort and accounting risk. However, its native financial calculations use Python floating point, which conflicts with the explicit requirement to avoid floating point arithmetic. Replacing its entire financial calculation path would be an invasive, costly fork and would erase much of its maintenance advantage.

Evidence is stronger than a general concern about rounding: Frappe **v16.51.0** defines `flt()` as a conversion to Python `float`, including `num = float(s)`; ERPNext **v16.50.0** computes an item amount using `flt(item.rate * item.qty, ...)` and uses the same utility in currency conversion and tax calculations. Frappe's MariaDB mapping stores Currency as `DECIMAL(21,9)`, but decimal storage does not make native arithmetic decimal-only. These facts establish a requirements mismatch; they do not establish that ERPNext is generally unsuitable for accounting. [Frappe numeric utility, pinned release](https://raw.githubusercontent.com/frappe/frappe/v16.51.0/frappe/utils/data.py), [ERPNext financial calculations, pinned release](https://raw.githubusercontent.com/frappe/erpnext/v16.50.0/erpnext/controllers/taxes_and_totals.py), [Frappe MariaDB type mapping](https://raw.githubusercontent.com/frappe/frappe/version-16/frappe/database/mariadb/database.py).

If TechPoint later explicitly changes that arithmetic requirement, revisit an ERPNext fit assessment before funding a full custom ledger. That assessment must test Kosovo fiscal integration, Albanian usability, per-cost-category import allocation, WooCommerce behavior, permissions, returns, and courier reconciliation with representative records. Neither platform has been verified here as Kosovo fiscal software.

| Criterion | Custom Laravel | ERPNext 16 with a separate extension app |
|---|---|---|
| Full accounting and stock lifecycle | Must design, implement, test, reconcile, and have the accountant validate it; highest custom-development risk | Existing accounting and stock workflows substantially reduce new implementation |
| Strict decimal-only financial arithmetic | Can enforce money value objects, decimal string APIs, exact arithmetic, and DECIMAL storage from the beginning | Native calculations conflict with the requirement, despite DECIMAL storage |
| TechPoint-specific returns, courier settlement, and sales channels | Precise control over workflow and interface | Configure standard workflows where suitable; extensions for the remaining gaps |
| Landed costs | Can allocate freight, duties, fees separately by value, weight, quantity, or volume, with audited rounding | Standard documented voucher distributes by amount or quantity; mixed allocation by cost category and weight/volume need fit-gap validation |
| Albanian interface | Translation keys and reviewed Albanian labels from the first increment | Translation completeness and financial terminology need hands-on validation; no claim of complete Albanian coverage |
| Kosovo fiscal connector | Custom provider adapter once authorization and API are verified | Custom provider adapter once authorization and API are verified |
| WooCommerce | Small first-party connector with read-only import, deduplication, reconciliation | Supported connector availability and suitability must be verified; a custom extension may be required |
| Runtime and operations | PHP-FPM, SQL, queue worker, scheduler, reverse proxy | Python web workers, Redis/Valkey, SQL, background workers, scheduler, realtime component, reverse proxy |
| Long-term ownership | TechPoint owns business logic, accounting correctness, regression coverage, and framework upgrades | Upstream owns much of core; TechPoint still owns extensions, integration behavior, upgrade regression testing, and operations |

ERPNext's documented capabilities include an immutable general and stock ledger with traceable reversals, and landed cost vouchers that update stock valuation and subsequent cost of goods sold. Those are valuable existing controls. They still require setup and scenario validation rather than assuming every requirement is automatically satisfied. [Immutable ledger](https://docs.frappe.io/erpnext/immutable-ledger-in-erpnext), [Landed cost voucher](https://docs.frappe.io/erpnext/stock-transactions-landed-cost-voucher), [General ledger](https://docs.frappe.io/erpnext/general-ledger).

For an ERPNext path, maintain a separate `techpoint` app, exported configuration fixtures, migration patches, and documented hooks. Avoid editing ERPNext/Frappe core. Frappe 16 supports `extend_doctype_class`, which allows extensions without replacing entire upstream classes; hooks are still a dependency contract to test after upgrades. [Official extension hooks](https://docs.frappe.io/framework/user/en/python-api/hooks).

## 2. Version choices and maintenance

| Component | Proposed custom stack | Verified support or rationale |
|---|---|---|
| Backend | Laravel **13.x**, pin the tested patch in the lockfile | Official release notes support PHP 8.3–8.5; security fixes through **17 March 2028**; bug fixes through Q3 2027. Annual major upgrade planning is required. |
| PHP | **8.5.x**, tested patch | Active support through **31 December 2027**, security through **31 December 2029**. PHP 8.4 is a fallback if a necessary dependency cannot support 8.5, with a documented upgrade date. |
| Database | **MySQL 8.4 LTS**, tested patch, InnoDB and utf8mb4 | Stable LTS series, with necessary fixes rather than feature churn inside the series. Oracle's commercial support lifecycle is not a promise of a free managed service. |
| MariaDB alternative | **11.4 LTS**, tested patch | Community maintenance through **29 May 2029**. Laravel supports MariaDB; choose one engine and test against it rather than promising interchangeable production behavior. |
| Host OS | **Ubuntu 24.04 LTS**, patched | Established provider-advertised image; official standard support to June 2029. Ubuntu 26.04 LTS is newer, but its presence in the actual provider image selector and the complete deployment matrix must be checked before choosing it. |
| Frontend | Vue 3, TypeScript, Inertia, and compiled CSS/JS assets | Root proposal selects Laravel's supported Vue starter-kit direction for reusable forms, tables, and the eventual POS. Keep authoritative calculations and permissions on the server. This remains one application, with no separate frontend server needed in production. [Official starter kit](https://laravel.com/framework/docs/13.x/starter-kits) |
| Queue and scheduler | Database-backed Laravel queue, dedicated worker, scheduler process | Supported database queue avoids an additional service for an initially modest workload. Queue monitoring, bounded retries, and failed-job handling remain mandatory. |

Primary sources: [Laravel 13 support policy](https://laravel.com/framework/docs/13.x/releases), [PHP support dates](https://www.php.net/supported-versions.php), [MySQL LTS policy](https://dev.mysql.com/doc/refman/8.4/en/mysql-releases.html), [MariaDB community lifecycle](https://mariadb.org/about/), [Laravel database support](https://laravel.com/framework/docs/13.x/database), [Laravel database queue](https://laravel.com/framework/docs/13.x/queues#database), [Ubuntu lifecycle](https://ubuntu.com/project/docs/release-team/list-of-releases/).

Use exact-decimal application calculations, with decimal inputs passed as strings and a tested decimal library or BCMath boundary. Unit costs, quantities, exchange rates, allocations, tax, and journal balances all need exact arithmetic and explicit rounding rules. Persist rounded posting amounts separately from higher-precision cost/FX values. Browser previews are advisory; the server calculates and confirms authoritative amounts. A framework does not provide this discipline automatically.

Ubuntu 24.04's distribution defaults must not be assumed to provide the chosen PHP 8.5 and MySQL 8.4 versions. Proposal: deploy a small Docker Compose stack with official versioned runtime/database images, a persistent database volume, and immutable release images built outside the production VPS. Lock tested versions or image digests, apply reviewed patches, and document the host/container update procedures. Native services are also viable if an equally reproducible, supported package source is established. No Kubernetes or microservices are justified.

For comparison, the current Frappe installation table specifies **MariaDB 11.8, Python 3.14, Node 24, Redis/Valkey 6+** for v16/develop and recommends Debian 13+ or Ubuntu 24.04+. It distinguishes v14/v15 dependencies. The public support plan lists ERPNext 16 through the **end of 2029, planned**, and 15 through the end of 2027, planned. MariaDB 11.8's community maintenance ends **4 June 2028**, so it needs a database upgrade before the ERP's planned end date. Do not confuse paid Enterprise/Extended support with free Community maintenance. [Frappe installation matrix](https://docs.frappe.io/framework/user/en/installation), [ERPNext support policy](https://github.com/frappe/erpnext/wiki/Supported-Versions), [MariaDB lifecycle](https://mariadb.org/about/).

## 3. Contabo Cloud VPS 6 — provider facts and verification limits

The directly accessible official product documentation identifies the contemporary **Core VPS / Cloud VPS 6** as follows. [Contabo VPS documentation](https://docs.contabo.com/docs/servers-hosting/vps/).

| Item | Provider publication | Still needs verification |
|---|---|---|
| CPU | **6 shared vCPU cores**, KVM virtualization | Actual CPU model, contention, CPU steal, and sustained application performance |
| RAM | **12 GB** allocation | Actual instance capacity and whether existing applications occupy it |
| Storage | Documentation: **200 GB SSD** | New indexed retail pages also advertise **200 GB SSD / 100 GB NVMe**. The documentation has not reflected that option, so confirm the checkout selection and invoice rather than assuming both capacities are included. |
| Port | **300 Mbit/s** | Actual network performance and reliability to Kosovo |
| Traffic | Unlimited under fair usage; possible throttling for exceptional/disruptive usage | Exact order/account conditions; do not reuse older 32 TB plan limits |
| Snapshots | **2 slots**, documentation states disk snapshots retained 30 days | Snapshots are supplementary recovery aids; they do not replace independent application backups |
| Network protection | Network firewall and DDoS protection included; IPv4 and IPv6 allocation | Correct host/container firewall configuration still required |

The indexed official retail and pricing pages show **€6.00 effective monthly price for the first 24 months** and a **€7.50 reference price** for Cloud VPS 6. Retail terms state prices include applicable taxes, minimum initial contract one month, subsequent term equal to initial term, and cancellation notice none for prepaid / four weeks for postpaid. **This is an advertised effective rate, not a verified Kosovo checkout quote or proof of monthly billing.** [Contabo Core VPS retail page](https://contabo.com/en-us/vps/), [Contabo pricing page](https://contabo.com/en-us/pricing/).

The euro currency is corroborated by Contabo's own 2026 Linux hosting article, which explicitly lists Core VPS 6 at **€6.00/month**. That article labels its table as a **12-month term**, while the retail offer says 24 months. This inconsistency is another reason to obtain the actual quote instead of treating an indexed price and contract term as a verified offer. [Contabo Linux hosting article](https://contabo.com/blog/linux-web-hosting/).

Direct reads of the retail/pricing pages returned **HTTP 403** during this research; their official search-index content was available. The selected country's VAT treatment, a one-month price, setup fee, upfront payment, renewal price, discount eligibility, regional extras, and add-on totals could not be verified in checkout. No order or checkout submission was made. Some older official indexed pages contain a legacy `Cloud VPS 6` priced €61.50 and older `Cloud VPS 20` specifications. These are not safe substitutes for the present Core VPS 6 SKU. [Legacy product list, illustrating name ambiguity](https://contabo.com/en/product-list/).

Official Linux marketing lists Ubuntu 22.04/24.04 and Debian 11 or higher, along with other distributions; the actual available image and version must be selected and inspected before installation. Linux images avoid a Windows license add-on. Hosting is unmanaged: application and OS maintenance belong to TechPoint/the maintainer. [Contabo Linux VPS](https://contabo.com/en-us/linux-vps/).

Before purchasing or using a server, record the exact plan/SKU, region, storage option, OS image, tax-inclusive invoice amount, billing commitment, setup fee, renewal, backups, and service terms. Then inspect the instance CPU/RAM/disk/OS, free space, running services, and resource load. Phase 0 has not performed those instance checks.

## 4. Resource estimates — engineering assumptions, not vendor guarantees

Initial sizing assumes one business, one central warehouse, roughly **5–10 simultaneous staff**, up to **10,000 SKUs**, **100–300 orders/day**, and moderate document attachments. TechPoint has not supplied actual volumes. These figures are planning assumptions to replace with observed workloads, not product capacity promises.

| Component | Custom Laravel working allowance | ERPNext working allowance |
|---|---:|---:|
| Host OS, reverse proxy, container runtime, basic monitoring | 0.6–1.0 GB RAM | 0.6–1.0 GB RAM |
| SQL database, tuned initial buffer pool and connection caps | 1.0–2.0 GB RAM | 1.5–2.5 GB RAM |
| Web workers | 0.5–1.0 GB RAM | 1.0–2.0 GB RAM |
| Background workers, scheduler, cache/realtime where needed | 0.3–0.8 GB RAM | 0.8–1.8 GB RAM |
| Backup/report burst reserve | 0.5–1.0 GB RAM | 0.5–1.0 GB RAM |
| Estimated working envelope before OS file cache/headroom | **2.9–5.8 GB RAM** | **4.4–8.3 GB RAM** |

Either stack appears feasible on a genuinely available 12 GB VPS for this assumed scale; RAM is not the reason to reject ERPNext. CPU contention, disk latency, report complexity, unbounded workers, valuation reposts, and concurrent backup jobs still determine practical performance. Keep at least 25–30% memory headroom and avoid building frontend/container images or running the full test suite on the live ERP host. A lightly used staging instance can share the VPS only with separate databases, secrets, uploads, and explicit resource limits; that provides logical separation, not independent fault isolation.

Disk planning: allow 15–25 GB for OS/runtime/releases, 5–10 GB for initial database/index growth, 10–20 GB for attachments, 5–10 GB for bounded logs and binary logs, and 10–20 GB for temporary backups/report workspace. With 20 GB free headroom, the planned envelope is about **65–105 GB**. A 100 GB NVMe option could be tight if attachments or logs grow; 200 GB SSD offers capacity at possible I/O tradeoffs. Measure before choosing. Keep documents private, cap uploads, rotate logs, and monitor free space.

Assumed initial egress including synchronization, reports, and backups: **10–100 GB/month**, highly sensitive to document size and backup churn. A 300 Mbit/s port is a provider specification, not a guarantee of upload/restore speed or low latency.

Before production, test simultaneous order processing, repeated imports, stock locking, weighted-average changes, ledger posting, reports, and restore under representative data volume. Acceptance includes no duplicate stock or journal effects and a documented p95 response target agreed with the business, not just a server health screenshot.

## 5. Recurring costs and backup proposal

| Item | Price or planning allowance | Status |
|---|---|---|
| Contabo Core VPS 6 | Indexed effective **€6/month** on a 24-month offer; **€7.50 reference** | Quote, Kosovo tax, commitment, setup, and renewal unverified |
| Nginx, Linux, PHP, Laravel, SQL, local monitoring | No proposed software subscription | Staff maintenance remains a real operating cost |
| HTTPS | Let's Encrypt certificates free | DNS access and automatic renewal still required; [official source](https://letsencrypt.org/) |
| Separate-provider encrypted backup storage example | Backblaze B2 **$6.95/TB/month**, first 10 GB free | A proposed option only; no account or subscription created |
| Example 50–100 GB average retained B2 backup data | Approximately **$0.28–$0.63/month** storage above the free 10 GB | Arithmetic estimate at published rate, excludes applicable tax, FX, excess egress, and exceptional transactions |
| Provider Auto Backup | Extra charge; amount not verified | Optional supplement, not the only backup |
| Existing domain/subdomain | No new domain purchase proposed | Confirm existing DNS access and any current host's administrative fees |
| Fiscal provider, local print bridge, technical support | **Unknown, additional** | Must obtain explicit approved quotes; not hidden in the server budget |

B2's directly readable official page states free egress up to three times average stored data each month, then $0.01/GB; ordinary API transaction classes are free, with a separate Class D rate. That pricing is sufficient for an initial storage estimate, not a guaranteed total charge or euro conversion. [Backblaze B2 pricing](https://www.backblaze.com/cloud-storage/pricing).

The **less-than-€10/month server target looks plausible**, but a confirmed all-in total cannot be asserted without the actual hosting quote and backup dataset. Development, accountant validation, fiscal software, printing, and maintenance labor are separate costs. If required security/backup or a fiscal provider makes the complete operating budget higher, disclose that and seek an approved budget; do not remove those controls.

Proposed backup policy: encrypted transaction-consistent database backups plus private attachments/configuration, copied daily to an independently controlled off-server repository; retain 7 daily, 4 weekly, and 12 monthly recovery points subject to legal document retention requirements. Keep encryption recovery material outside the VPS and backup write credentials scoped as tightly as the chosen backend supports. Preserve an additional owner-controlled offline copy periodically. Prevent the live app user from deleting archived backups where feasible. These retention points need measured capacity, not an assumption that 100 GB always suffices.

Suggested initial recovery objectives to approve: **RPO no more than 24 hours, RTO within 4 hours**. For lower data-loss tolerance, archive database binary logs and new files off-server more frequently and adjust cost and monitoring. Restoration drills must include the database, attachments, permissions, application version, and secrets. After a restore, pause outbound fiscal/payment/stock jobs until externally completed operations and post-backup transaction effects have been reconciled and recovered; a restored database can have lost an issuance/idempotency record even though the external operation succeeded. Include this reconciliation in measured recovery time. Start with an isolated restore before go-live and repeat after material schema/storage changes, with a quarterly scheduled drill. A disk snapshot alone may not provide a transaction-consistent application restore.

## 6. Deployment and readiness gates

Design for a dedicated ERP application/database, isolated from the existing WordPress/WooCommerce hosting. Initial synchronization is read-only from WooCommerce; DNS or stock ownership changes wait for the approved cutover. The architecture should support Nginx HTTPS, restricted SSH keys, host plus container-network firewall rules, SQL reachable only privately, least-privilege database users, secrets outside Git, safe production logs, supervised workers, health checks, disk/queue/backup monitoring, and dependency patch ownership.

Release deployment should use reproducible images, a versioned release, compatible migrations, bounded worker draining, a database backup, verification, and a documented rollback. Reverting an image cannot undo an incompatible destructive migration; use additive migrations and separately approved schema cleanup. Monitor TLS expiry, backup age/failure, storage exhaustion, failed jobs, integration reconciliation, and application errors. Owner contact/delivery mechanism for alerts is still unknown.

The following are unresolved infrastructure questions before implementation/deployment:

1. Does TechPoint already own a VPS? Which exact SKU, region, storage, Linux version, billing term, and invoice total?
2. Is the €10 constraint for the VPS alone or all recurring technical services, and is tax included?
3. What are SKU count, orders/day, historical orders, staff concurrency, attachment volume, and growth?
4. Who controls DNS and the server, maintains patches, receives alerts, and holds independent backup recovery keys?
5. Which off-server backup location is approved, what retention is legally required, and are the proposed RPO/RTO acceptable?
6. Who will maintain PHP/Laravel and review the custom accounting logic with TechPoint's accountant?
7. Which fiscal provider/API and printer mechanism are authorized, and what extra charges apply?

Phase 1 remains subject to the user's requested approval after reviewing the overall Phase 0 proposal.
