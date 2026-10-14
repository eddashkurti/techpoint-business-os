# Operating TechPoint Business OS

## First setup

Sign in using the local access file, change the password and enable two-factor authentication. The owner controls users/roles. The accountant can review, reconcile bank records and export finance data; posting/refunds/closing privileges are separate permissions. Business settings must be decided with the accountant, not inferred from the existence of SSMS or the website. Create non-overlapping open accounting periods before posting.

## Purchasing and warehouse

Create categories, suppliers and products with unique SKUs/barcodes. Enter purchase packaging conversion before ordering; purchase quantities are in the purchasing unit and receipt quantities are base sales units. Create the purchase order, add final documented landed costs and allocation bases, then confirm actual received quantities. No stock is added while the order is merely a draft. Purchase detail keeps customs/invoice attachments private.

Stock adjustments require owner-level adjustment permission and a substantive reason. Positive adjustments require a documented total value. A stock count is saved against the observed book balance; confirmation is refused if that balance changed meanwhile. Transfer physically damaged goods into the damaged condition; perform a separate authorized write-off if the value must be removed. Do not count reserved stock as freely available stock.

For supplier physical returns, open the original purchase, choose the received line, condition and quantity, and provide return documentation. Returned inventory creates a supplier claim in 1310 at weighted-average removed value. The accountant reconciles the actual supplier credit/VAT separately. This does not silently change the original purchase invoice.

## Sales, POS and returns

POS supports keyboard-style barcode entry and product lookup, string quantities, line discounts for authorized users, optional customers and the selected sales channel. The server calculates the quote. Accepting an order reserves available warehouse stock. Open the sale to confirm actual dispatch and select the courier/tracking reference. Confirm delivery separately if delivery-based recognition is configured. Fiscal-required completion/payment actions stay blocked without matching provider confirmation.

Record partial cash/bank receipts only against recognized amounts. Prepayments are unavailable in this release. A cancellation releases remaining undelivered reservations, rather than restoring previously shipped items automatically.

Create a financial credit from the specific recognized fulfilment line. The goods remain outside stock until warehouse inspection and receipt through the return detail page. Choose sellable/quarantine/damaged condition. Refund money separately, within the credit and actual payment bounds. Never create a second sale credit just because a refund request is retried.

Failed deliveries are marked with a reason. An unrecognized returned parcel restores inventory/transit cost only on actual receipt. A recognized sale requires the financial credit/return workflow. Courier collection records money owed by the courier. The settlement screen records actual bank/cash remittance and fees; any shortage remains outstanding.

## Finance and accounting

Create supplier bills with actual invoice references. Use 2150 for received inventory invoices and the correct expense account for operating costs. This avoids expensing purchases twice. Enter recoverable VAT only when the confirmed business policy permits it. Record actual supplier payments separately. Recurring expenses create no automatic fictitious invoices: confirm each actual invoice and its tax fields.

Draft journals can be edited before posting; they have no effect on balances. Posting validates one-sided lines, balance and an open period. Posted manual entries are corrected through an authorized reversal and replacement; automated source transactions use their source workflows. Closing a period prevents further dated posting. Use bank matching to link an imported statement record to an existing cash/bank journal without posting cash again.

## Reports, documents and integrations

Dashboard/report filters select dates, products and channels. Reports explain current balances versus period activity. Monthly tables provide numeric chart values and historical comparisons. CSV exports preserve decimals and can be opened in Excel. Statements for filing still need accountant validation.

Product detail provides a Code 128 label print view using the maintained Picqer library (https://github.com/picqer/php-barcode-generator). The barcode is generated and HTML-tested; physical scanning remains an acceptance step.

Internal receipt printing opens the browser print dialog and says `NUK ËSHTË KUPON FISKAL`. An ordinary receipt is not a certified fiscal receipt. Silent printing, the physical XPrinter and the Netum scanner have not been tested here.

Create Woo connections only with official read-only keys and a long independent webhook secret. Reconciliation reads snapshots and never changes the live site. Credentials are never returned to the screen. Pending/failed webhook events can be retried from integration detail; the queue worker must be running. Production includes queue/scheduler services. Local testing can run `docker compose ... run --rm app php artisan queue:work` in a separate WSL terminal when a test connection exists.

If fiscal status is unknown, stop and reconcile with the provider using the original operation key. A timeout does not prove failure and must not lead to a new receipt submission. No certified production provider has been selected or configured.
