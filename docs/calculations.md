# Calculation and integrity rules

All money/quantity input travels as decimal strings. PHP uses Brick Math and MySQL uses DECIMAL; no financial totals depend on binary floating point. EUR ledger amounts have two places, stock quantities six and inventory values eight. Cent allocation uses a largest-remainder method so allocated amounts equal the original cost exactly.

## Inventory and landed cost

Packaging converts entered purchase quantities to base units using a saved exact conversion. The original invoice value is retained independently of rounded per-base-unit cost. A purchase order alone changes no stock. Confirmed receipts add base purchase value converted to EUR and attributable allocated cost; partial receipts retain residual value for the final receipt. Import VAT recovery is split before capitalization. Each confirmed receipt debits inventory and credits goods received not invoiced (2150). Actual supplier/transport invoices debit the clearing account or operating expense as appropriate and credit payables; do not record an inventory purchase as a second operating expense.

Weighted-average dispatch cost = current value × dispatched quantity / current quantity. Final depletion consumes all remaining value. Ledger movement = rounded ending inventory value minus rounded starting value, preventing cumulative cent drift. Returns restore the original fulfilment cost and record any cent difference separately. Reservations reduce availability, not physical quantity or value. Quarantined/damaged stock remains valued until an authorized write-off.

## Sales and payments

Prices and delivery charges are net of VAT; tax rates apply only according to confirmed business settings. Discounts are line amounts and price overrides require permission. Order acceptance reserves stock without revenue. Dispatch consumes reservations and creates one immutable movement per source/product/condition. Recognition policy is captured on the order and cannot be changed retroactively: dispatch recognizes revenue immediately, or delivery transfers cost through goods in transit (1210) until confirmation.

Receipts of cash/bank money settle receivables; they do not create revenue again. Courier collection moves a customer's receivable to a courier receivable and creates no cash. Courier remittance clears only actual cash plus documented fees; a shortfall remains receivable. Bank matching links an existing journal rather than creating another cash transaction.

Financial credits reverse recognized revenue/VAT but do not put goods into stock. Physical receipt is a separate inspected workflow. A refund is bounded by the credit and actual payments; it cannot duplicate the credit or automatically restore stock. Failed delivery does not restore stock automatically. Unrecognized returned parcels use inventory/transit reversal, while recognized orders use the credit/return workflow.

## Reports

Revenue = revenue-account credits − debits. Gross profit = revenue − net COGS (5000). Operating profit = gross profit − other expense-account net debits. This is not after-tax statutory net profit. Advertising is 6000; domestic postage is 5100. Cash flow is cash/bank net debit movement. Detailed inflow/outflow excludes internal transfers. Receivables/payables and inventory cards show current all-period balances. Inventory includes both warehouse inventory (1200) and goods in transit (1210).

Date/channel/product filters select posted activity. Product margin comes from revenue and COGS lines attributed to that product; physically returned goods affect cost on receipt date. Monthly charts use 12 calendar months ending in the selected final month. Average order value uses created order gross totals, including cancelled orders, and is distinct from recognized sales. Budget actuals are expense-account movements for each budget month.

The current chart uses fixed control codes for automated posting; additional reporting accounts can be created. Shared overhead does not become a channel or product expense without an explicit allocation. Comparisons are available from monthly reports; no invented missing-history values appear.

## Posting safety

One transaction wraps source updates, stock, journal, audit and idempotency results. Request-key reuse with different data is rejected; exact replay returns the first result. Rows and products lock during stock changes. Negative available stock, out-of-bounds returns/payments and closed-period posting fail atomically. Posted journal headers/lines, stock movements, payments, refunds and audit events have MySQL mutation-blocking triggers. Corrections use documented reversal/source workflows. A privileged migration service creates triggers; the runtime application never receives root database credentials.
