<?php

namespace App\Http\Controllers;

use App\Services\CsvImportService;
use App\Services\FinanceExtensions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class ExtensionsController extends Controller
{
    public function deposit(Request $request, FinanceExtensions $extensions)
    {
        Gate::authorize('finance.manage');
        $result = $extensions->receiveDeposit($request->validate($this->depositRules()));

        return $this->result($request, $result, '/customer-deposits', 'Parapagimi u regjistrua.');
    }

    public function allocateDeposit(Request $request, int $deposit, FinanceExtensions $extensions)
    {
        Gate::authorize('finance.manage');
        $data = $request->validate(['operation_key' => 'required|string|min:8|max:100', 'sale_id' => 'required|integer|exists:sales,id', 'amount' => $this->money(), 'allocated_on' => 'required|date', 'reason' => 'required|string|min:5|max:1000']);

        return $this->result($request, $extensions->allocateDeposit($deposit, $data), '/customer-deposits/'.$deposit, 'Parapagimi u shpërnda.');
    }

    public function refundDeposit(Request $request, int $deposit, FinanceExtensions $extensions)
    {
        Gate::authorize('finance.manage');
        $data = $request->validate(['operation_key' => 'required|string|min:8|max:100', 'amount' => $this->money(), 'account_id' => 'required|integer|exists:accounts,id', 'refunded_on' => 'required|date', 'reason' => 'required|string|min:5|max:1000']);

        return $this->result($request, $extensions->refundDeposit($deposit, $data), '/customer-deposits/'.$deposit, 'Parapagimi u rimbursua.');
    }

    public function supplierCredit(Request $request, FinanceExtensions $extensions)
    {
        Gate::authorize('finance.manage');
        $data = $request->validate(['operation_key' => 'required|string|min:8|max:100', 'supplier_id' => 'required|integer|exists:parties,id', 'bill_id' => 'nullable|integer|exists:bills,id', 'purchase_order_id' => 'nullable|integer|exists:purchase_orders,id', 'supplier_return_id' => 'nullable|integer|exists:supplier_returns,id', 'account_id' => 'required|integer|exists:accounts,id', 'reference' => 'required|string|max:100', 'credited_on' => 'required|date', 'net_amount' => $this->money(), 'vat_amount' => $this->money(), 'recoverable_vat' => $this->money(), 'reason' => 'required|string|min:5|max:1000']);

        return $this->result($request, $extensions->supplierCredit($data), '/supplier-credits', 'Kreditimi i furnitorit u regjistrua.');
    }

    public function allocateSupplierCredit(Request $request, int $credit, FinanceExtensions $extensions)
    {
        Gate::authorize('finance.manage');
        $data = $request->validate(['operation_key' => 'required|string|min:8|max:100', 'bill_id' => 'required|integer|exists:bills,id', 'amount' => $this->money(), 'allocated_on' => 'required|date']);

        return $this->result($request, $extensions->allocateSupplierCredit($credit, $data), '/supplier-credits/'.$credit, 'Kreditimi u lidh me faturën.');
    }

    public function postReceiptCost(Request $request, int $purchase, FinanceExtensions $extensions)
    {
        Gate::authorize('purchasing.manage');
        $data = $request->validate(['operation_key' => 'required|string|min:8|max:100', 'supplier_id' => 'nullable|integer|exists:parties,id', 'credit_account_id' => 'required|integer|exists:accounts,id', 'document_reference' => 'required|string|max:100', 'category' => ['required', Rule::in(['freight', 'customs', 'clearance', 'insurance', 'domestic_transport', 'other'])], 'allocation_basis' => ['required', Rule::in(['value', 'quantity', 'weight', 'volume'])], 'amount' => $this->money(), 'accounting_date' => 'required|date', 'reason' => 'required|string|min:5|max:1000']);

        return $this->result($request, $extensions->postReceiptCostAdjustment($purchase, $data), '/purchases/'.$purchase, 'Kostoja e vonuar u shpërnda.');
    }

    public function creditDelivery(Request $request, int $sale, FinanceExtensions $extensions)
    {
        Gate::authorize('sales.refund');
        $data = $request->validate(['operation_key' => 'required|string|min:8|max:100', 'fulfillment_id' => 'required|integer|exists:fulfillments,id', 'reference' => 'required|string|max:100', 'credited_on' => 'required|date', 'amount' => $this->money(), 'reason' => 'required|string|min:5|max:1000']);

        return $this->result($request, $extensions->creditDelivery($sale, $data), '/sales/'.$sale, 'Tarifa e dërgesës u kreditua.');
    }

    public function refundDeliveryCredit(Request $request, int $credit, FinanceExtensions $extensions)
    {
        Gate::authorize('sales.refund');
        $data = $request->validate(['operation_key' => 'required|string|min:8|max:100', 'account_id' => 'required|integer|exists:accounts,id', 'amount' => $this->money(), 'refunded_on' => 'required|date', 'reason' => 'required|string|min:5|max:1000']);

        return $this->result($request, $extensions->refundDeliveryCredit($credit, $data), '/delivery-credits/'.$credit, 'Rimbursimi i tarifës u regjistrua.');
    }

    public function imports()
    {
        Gate::authorize('settings.manage');

        return Inertia::render('business/Imports', ['accounts' => DB::table('accounts')->whereIn('code', ['1000', '1010'])->where('active', true)->select('id', 'code', 'name')->orderBy('code')->get(), 'batches' => DB::table('import_batches')->orderByDesc('id')->limit(100)->get()]);
    }

    public function import(Request $request, string $kind, CsvImportService $imports)
    {
        Gate::authorize('settings.manage');
        abort_unless(in_array($kind, ['products', 'customers', 'suppliers', 'opening-balances', 'historical-records', 'bank'], true), 404);
        $rules = ['file' => 'required|file|max:10240|mimes:csv,txt'];
        if ($kind === 'bank') {
            $rules['account_id'] = 'required|integer|exists:accounts,id';
        }
        $data = $request->validate($rules);
        $result = $imports->import($kind, $data['file'], $data);

        return $this->result($request, $result, $kind === 'bank' ? '/bank' : '/imports', 'Importi u përfundua.');
    }

    public function importTemplate(string $kind, CsvImportService $imports)
    {
        Gate::authorize('settings.manage');
        abort_unless(in_array($kind, ['products', 'customers', 'suppliers', 'opening-balances', 'historical-records', 'bank'], true), 404);

        return response(implode(',', $imports->templateColumns($kind))."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="techpoint-'.$kind.'-template.csv"',
        ]);
    }

    public function previewImport(Request $request, string $kind, CsvImportService $imports)
    {
        Gate::authorize('settings.manage');
        abort_unless(in_array($kind, ['products', 'customers', 'suppliers', 'opening-balances', 'historical-records', 'bank'], true), 404);
        $data = $request->validate(['file' => 'required|file|max:10240|mimes:csv,txt']);

        return response()->json($imports->preview($data['file']));
    }

    private function result(Request $request, array $result, string $redirect, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json($result, 201);
        }

        return redirect($redirect)->with('success', $message);
    }

    private function money(): string
    {
        return 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/';
    }

    private function depositRules(): array
    {
        return ['operation_key' => 'required|string|min:8|max:100', 'customer_id' => 'required|integer|exists:parties,id', 'account_id' => 'required|integer|exists:accounts,id', 'reference' => 'required|string|max:100', 'amount' => $this->money(), 'method' => 'required|string|max:40', 'received_on' => 'required|date', 'reason' => 'required|string|min:5|max:1000'];
    }
}
