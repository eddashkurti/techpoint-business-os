# TechPoint Business OS — Phase 0 fiscalization evidence

Research date: **14 October 2026**. This is implementation discovery, not a determination of TechPoint's individual tax position. No provider was contacted, no account was accessed, and no fiscal request was submitted.

## Findings that change the plan

The current official starting portal publishes **Administrative Instruction (MF) No. 01/2026**, alongside the technical requirements, an amendment, certification procedures and a testing guide. The Official Gazette records publication on **27 April 2026** and links Law No. 08/L-257 as its legal basis. A plan relying only on the 2025 instruction would miss current materials. The Gazette explicitly records repeal of the 2018 instruction; this research does not infer the legal history of every 2025 instrument. [Official Gazette record](https://gzk.rks-gov.net/ActDetail.aspx?ActID=119286), [ATK implementation portal](https://apps.atk-ks.org/sefaplikimi).

ATK's certified SEF register returned **23 software/platform records from 22 companies**; easyPos appears in two platform records. The register says it is updated continuously. These are dated observations, not a permanent approved-vendor list. [ATK certified SEF register](https://apps.atk-ks.org/sefaplikimi/PublicSEF).

On **9 July 2026**, ATK announced EDI's “Request for Fiscalization” service and identified a Unique Fiscalization Code as a prerequisite for SEF use. On **1 June 2026**, ATK opened the current SEF certification/maintenance application process under the 2026 instruction. Public samples therefore establish a technical path, but do not establish authorization for an uncertified custom ERP to issue fiscal receipts. [EDI notice](https://www.atk-ks.org/en/notice-to-taxpayers-new-version-of-the-edi-electronic-system-published/), [Certification notice](https://www.atk-ks.org/en/notice-to-taxpayers-apply-for-certification-and-maintenance-of-electronic-fiscal-software-efs-2/).

## Applicable requirements: confirmed rule, conditional application

The following is a compact reading of the 2026 instruction; article references allow verification:

- **Articles 5–6:** supplies to final consumers require fiscal receipts regardless of payment method; advance payments also matter. Deferred payment changes the issue timing to supply.
- **Article 31:** retail/public-facing activity is within SEF scope. Exclusively business-to-business supply has an invoice exception; mixed activity still registers non-exempt transactions.
- **Articles 7, 32–34:** receipts require identifiers, line/tax/payment details, daily number and QR; SEF uses digital certificates, signatures, HTTPS, printer integration and secure outage storage followed by automatic transmission.
- **Article 39:** paper or electronic delivery is allowed; electronic receipt acceptance requires buyer consent.
- **Article 43:** correcting receipts reference the original.
- **Article 44:** restore internet and submit within 48 hours; documented inability requires ATK notification and subsequent fiscalization, with further reporting rules for prolonged outages.
- **Article 50:** transition from existing devices is set by ministerial decision; a 60-day SEF installation rule applies to relevant taxpayers without PEF/SF at entry into force.

[Official 2026 instruction, articles 5–7, 31–34, 39, 43–44 and 50](https://apps.atk-ks.org/sefaplikimi/uploads/documents/87a43adf-6ee2-42e5-a5af-043c031f514f.pdf).

**Application to TechPoint remains conditional:** the described consumer retail sales suggest fiscal receipt obligations, but the legal entity/NUI, current fiscal device, registered activities, any applicable exemption or transition decision, VAT status, actual supply/payment terms and courier arrangements are not supplied. VAT registration must not be inferred from fiscalization requirements. Configure tax treatment only after the accountant/ATK resolves these facts. Website order creation, customer payment, shipment, delivery and courier remittance must remain distinct events; do not choose the fiscal issue event from a WooCommerce status name alone.

ATK announced that, from **15 May 2026**, fiscalization of special tax receipt blocks is performed electronically in EDI under Guide No. 02/2026, generating a unique block code, with at most 50 coupons per block. Its notice covers absent fiscal equipment, temporary equipment failure and transition to SEF. An emergency internal PDF is not this authorized block procedure. [ATK special-block notice](https://www.atk-ks.org/njoftim-per-tatimpagues-fiskalizimi-i-bllokave-tatimore-vetem-ne-menyre-elektronike/).

## Verified technical information and limits

ATK's implementation portal directly links the GitHub organization **fiskalizimi**, containing C#, Go, PHP and Ruby repositories. This provenance is stronger than an unrelated vendor tutorial. [ATK portal](https://apps.atk-ks.org/sefaplikimi), [Linked organization](https://github.com/fiskalizimi).

The C# sample documents Protobuf models, ECDSA signing, certificate onboarding, test/production environments, `SALE`, `CANCEL` and `RETURN`, original-receipt references, business-wide unique coupon IDs and a returned transaction identifier. It links ATK test Swagger. Each POS has its own key pair; its warning requires the private key to stay on the originating machine. Monetary notes specify item prices in 0.0001 EUR units and totals in cents. Later field descriptions call prices “cents”, and RETURN descriptions contain apparent SALE wording errors. Resolve these inconsistencies against current specifications and tests before implementation. Samples also warn that example taxes do not demonstrate tax calculation. Public documentation is evidence of a protocol, not evidence of successful onboarding or production access for TechPoint. [ATK-linked C# sample](https://github.com/fiskalizimi/pos-csharp), [Test Swagger](https://fiskalizimi-test.atk-ks.org/swagger/index.html).

The test Swagger URL loaded as a JavaScript shell without machine-readable endpoint content in this research. No API operation was executed. The PHP repository contains an example service URL; that placeholder must never be treated as a real ATK endpoint. [ATK-linked PHP sample](https://github.com/fiskalizimi/pos-php).

The portal's published **88-page technical requirements**, **three-page amendment**, and **seven-page certification procedures** were located; their scanned text was not fully extracted here. They require full review/version capture during provider discovery. This report does not invent details from those scans. [Technical requirements](https://apps.atk-ks.org/sefaplikimi/uploads/documents/150ab6cc-084f-490d-b5fc-d8639d0287dc.pdf), [Amendment](https://apps.atk-ks.org/sefaplikimi/uploads/documents/4994a4cd-75c8-46fd-bb7c-d541e4aa0e40.pdf), [Certification procedures](https://apps.atk-ks.org/sefaplikimi/uploads/documents/0d8c2056-98ad-403a-a685-57e8cf33d1cc.pdf), [Testing/application guide](https://apps.atk-ks.org/sefaplikimi/uploads/documents/1e869fe5-02a2-4471-95a8-0ec6844d6f12.pdf).

## Provider comparison: verified candidates for investigation

These three examples have individually verified ATK certification records. They are a diligence list, not a selected provider or a claim that third-party ERP integration works. Certification attaches to the listed software/version/platform; do not generalize it to every service the company sells.

| Company / product | Verified official record | Integration and workflow evidence | Costs / limitation / open question |
|---|---|---|---|
| Informatika Computers SH.P.K / Rikont FiscalListener | SEF 868032258808; v1.0.18; Windows; certified 15 September 2026. [ATK detail](https://apps.atk-ks.org/sefaplikimi/PublicSEF/Details/20) | Third-party API, SDK or local service contract not verified. Product name alone establishes no listener interface. Provider site timed out in this pass. | Setup, subscription, per-receipt, API, support and certificate fees unknown. Ask for supported Laravel integration, sandbox and XP-Q80CS compatibility. |
| TECH VISION SH.P.K. / SEF Connect | SEF 831310272225; v1.0.0; Windows; certified 1 October 2026. [ATK detail](https://apps.atk-ks.org/sefaplikimi/PublicSEF/Details/26) | API/SDK/local-service documentation not verified. Provider site exposed only a JavaScript-required message in this pass. [Provider site](https://techvision-ks.com/) | All commercial fees unknown. Ask whether an independent ERP can submit, query status and recover an ambiguous response using the certified product. |
| Kuleta Platform L.L.C. / Kuleta Terminal | SEF 808356410690; v1.0.0; Windows/macOS; certified 27 August 2026. [ATK detail](https://apps.atk-ks.org/sefaplikimi/PublicSEF/Details/18) | Site describes accounting/inventory/POS and says “launching soon”; no public ERP API contract was verified. These are vendor statements, not tested capabilities. [Provider site](https://kuleta.app/) | All fees unknown. Ask about standalone fiscal integration, availability, exports and lock-in; its broader cloud ERP may overlap the custom system. |

**For all three:** tested third-party issuance, cancellation/refund operations, retry deduplication, offline recovery, receipt retrieval, statutory storage, Linux-server connectivity, printer support, silent printing, third-party authorization and any additional certification are **unverified**. The ATK listing verifies certification facts; it does not prove these capabilities or zero cost. No XP-Q80CS fiscal compatibility claim is made.

## Questions that close the discovery gate

**For TechPoint/accountant:** identify legal entity and NUI; VAT registration and effective dates; existing approved fiscal device/software; outlets and POS terminals; consumer versus business sales; cash/card/transfer/prepaid/COD terms; who issues the receipt for courier deliveries; current receipt workflow and retained records.

**For ATK:** which transition decision applies to this taxpayer and existing device; precise supply/payment event for shipped COD orders and failed deliveries; electronic consent/receipt delivery procedure; online-sales POS/branch registration; treatment of delivery charges; special-block contingency; retention requirements; whether using a certified provider's supported interface requires additional ERP certification; whether server deployment/key custody is allowed for the intended topology. ATK's current certification notice supplies its official contact route; no message was sent.

**For each candidate provider:** obtain versioned API/SDK/service documentation and sandbox access; written third-party permission and certification boundary; full quote including taxes/setup/API/per-POS/per-receipt/support/certificates; supported Windows/local agent and Linux ERP topology; supported printer interfaces/drivers; Albanian characters, 80 mm format and QR readability; return/cancel/partial-refund examples; status query and duplicate response behavior; network timeout and service outage recovery; signed receipt export and audit retention; certificate renewal/revocation; supported WooCommerce and COD workflows.

## Proposed implementation gate and interface

Keep the fiscal adapter pending until a selected certified solution provides an authorized, documented path and passes sandbox scenarios. The ERP should store immutable requests, original-sale references, provider/application/POS identity, unique operation identity, attempt history, provider identifiers and verification artifacts. Separate `draft`, `ready`, `submitted`, `confirmed`, `rejected`, `unknown` and any explicitly supported offline state. A timeout after submission becomes `unknown`; query/reconcile before reissuing. Local uniqueness cannot prove the provider deduplicates requests.

The authorized provider/POS agent should hold fiscal keys according to the confirmed ATK/provider model. Do not assume the VPS may hold every till's private key. Require real external confirmation before labeling a fiscal document confirmed. Ordinary sale completion must honor the selected legally required issue event; outage completion is available only through a verified authorized contingency workflow. Internal acknowledgments must identify themselves clearly as internal documents.

Acceptance evidence must cover real sandbox sale, duplicate attempt, ambiguous timeout, correction/return referencing the original, partial return if supported, outage recovery, reconciliation and a physical XP-Q80CS print/QR scan. Completion and cost claims remain blocked by provider terms, taxpayer facts and these tests.
