<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Integrations\WooCommerce\ProcessWooWebhook;
use App\Services\Audit;
use App\Services\Finance;
use App\Services\Posting;
use App\Services\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class MaintenanceController extends Controller
{
    private function result(Request $r, array $result, string $message)
    {
        return $r->expectsJson() ? response()->json($result) : back()->with('success', $message);
    }

    public function product(Request $r, int $id)
    {
        Gate::authorize('inventory.manage');
        $d = $r->validate(['name' => 'required|string|max:255', 'category_id' => 'nullable|integer|exists:categories,id', 'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products')->ignore($id)], 'selling_price' => 'required|string|regex:/^\d{1,15}(\.\d{1,8})?$/', 'minimum_stock' => 'required|string|regex:/^\d{1,12}(\.\d{1,6})?$/', 'tax_rate' => 'required|string|regex:/^\d{1,3}(\.\d{1,4})?$/', 'active' => 'required|boolean', 'notes' => 'nullable|string|max:3000', 'reason' => 'required|string|min:5|max:1000']);
        if (Posting::decimal($d['tax_rate'])->compareTo('100') > 0) {
            throw new \DomainException('Norma e TVSH nuk mund të tejkalojë 100%.');
        }
        DB::transaction(function () use ($id, $d) {
            DB::table('products')->where('id', $id)->lockForUpdate()->firstOrFail();
            if (! $d['active'] && DB::table('stock_balances')->where('product_id', $id)->where('reserved', '>', '0')->exists()) {
                throw new \DomainException('Lironi rezervimet përpara çaktivizimit.');
            } $values = $d;
            unset($values['reason']);
            DB::table('products')->where('id', $id)->update($values + ['updated_at' => now()]);
            Audit::record('product.updated', 'product', $id, ['changes' => $values, 'reason' => $d['reason']]);
        });

        return $this->result($r, ['id' => $id], 'Katalogu u përditësua; dokumentet historike ruajnë vlerat e tyre.');
    }

    public function recurring(Request $r, int $id, Posting $posting, Finance $finance)
    {
        Gate::authorize('finance.manage');
        $d = $r->validate(['operation_key' => 'required|string|min:8|max:100', 'reference' => 'required|string|max:100', 'accounting_date' => 'required|date', 'vat_amount' => 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/', 'recoverable_vat' => 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/']);
        $result = $posting->once('recurring.confirm', $d + ['recurring_id' => $id], function () use ($id, $d, $finance) {
            $expense = DB::table('recurring_expenses')->where('id', $id)->lockForUpdate()->firstOrFail();
            if (! $expense->active || $expense->next_on > $d['accounting_date']) {
                throw new \DomainException('Shpenzimi nuk është ende për konfirmim.');
            }
            $bill = $finance->bill($d + ['supplier_id' => $expense->supplier_id, 'account_id' => $expense->account_id, 'net_amount' => $expense->amount, 'category' => 'recurring', 'description' => $expense->name]);
            DB::table('recurring_runs')->insert(['recurring_expense_id' => $id, 'due_on' => $expense->next_on, 'bill_id' => $bill['id']]);
            DB::table('recurring_expenses')->where('id', $id)->update(['next_on' => CarbonImmutable::parse($expense->next_on)->addMonthNoOverflow()->toDateString(), 'updated_at' => now()]);
            Audit::record('recurring.confirmed', 'recurring_expense', $id, ['bill_id' => $bill['id']]);

            return $bill;
        });

        return $this->result($r, $result, 'Fatura periodike u postua vetëm pas konfirmimit.');
    }

    private function draftRules(): array
    {
        return ['accounting_date' => 'required|date', 'reference' => 'required|string|max:100', 'description' => 'required|string|min:5|max:1000', 'lines' => 'required|array|min:2', 'lines.*.account_id' => 'required|integer|exists:accounts,id', 'lines.*.debit' => 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/', 'lines.*.credit' => 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/'];
    }

    public function draft(Request $r, ?int $id = null)
    {
        Gate::authorize('accounting.post');
        $d = $r->validate($this->draftRules());
        $saved = DB::transaction(function () use ($d, $id) {
            $values = $d;
            $values['lines'] = json_encode($d['lines'], JSON_THROW_ON_ERROR);
            $values['updated_at'] = now();
            if ($id) {
                $draft = DB::table('journal_drafts')->where('id', $id)->lockForUpdate()->firstOrFail();
                if ($draft->status !== 'draft') {
                    throw new \DomainException('Regjistrimi është postuar.');
                } DB::table('journal_drafts')->where('id', $id)->update($values);
            } else {
                $id = DB::table('journal_drafts')->insertGetId($values + ['user_id' => auth()->id(), 'created_at' => now()]);
            } Audit::record('journal.draft_saved', 'journal_draft', $id);

            return $id;
        });

        return $this->result($r, ['id' => $saved], 'Drafti u ruajt. Ai nuk ndryshon bilancin kontabël.');
    }

    public function postDraft(Request $r, int $id, Posting $posting)
    {
        Gate::authorize('accounting.post');
        $d = $r->validate(['operation_key' => 'required|string|min:8|max:100']);
        $result = $posting->once('draft.post', $d + ['draft_id' => $id], function () use ($id, $posting, $d) {
            $draft = DB::table('journal_drafts')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($draft->status !== 'draft') {
                throw new \DomainException('Drafti është postuar.');
            } $journal = $posting->journal('manual', $d['operation_key'], $draft->accounting_date, $draft->reference.' / '.$draft->description, json_decode($draft->lines, true, 512, JSON_THROW_ON_ERROR));
            DB::table('journal_drafts')->where('id', $id)->update(['status' => 'posted', 'updated_at' => now()]);
            Audit::record('journal.draft_posted', 'journal_draft', $id, ['journal_id' => $journal]);

            return ['id' => $journal];
        });

        return $this->result($r, $result, 'Drafti i balancuar u postua në periudhën e hapur.');
    }

    public function condition(Request $r, int $id, Posting $posting, Stock $stock)
    {
        Gate::authorize('inventory.manage');
        $d = $r->validate(['operation_key' => 'required|string|min:8|max:100', 'quantity' => 'required|string|regex:/^\d{1,12}(\.\d{1,6})?$/', 'from' => 'required|in:sellable,quarantine,damaged', 'to' => 'required|in:sellable,quarantine,damaged|different:from', 'accounting_date' => 'required|date', 'reason' => 'required|string|min:5|max:1000']);
        $result = $posting->once('stock.condition', $d + ['product_id' => $id], function () use ($id, $d, $posting, $stock) {
            $q = Posting::decimal($d['quantity']);
            if (! $q->isPositive()) {
                throw new \DomainException('Sasia duhet të jetë pozitive.');
            }
            $out = $stock->move($id, (string) $q->negated(), null, $d['from'], 'condition_transfer', 'condition_transfer', $d['operation_key'], $d['reason'], $d['accounting_date']);
            $in = $stock->move($id, (string) $q, (string) Posting::decimal($out->movementValue)->negated(), $d['to'], 'condition_transfer', 'condition_transfer', $d['operation_key'], $d['reason'], $d['accounting_date']);
            $delta = Posting::decimal($in->ledgerMovementValue())->plus($out->ledgerMovementValue());
            if (! $delta->isZero()) {
                $v = Posting::cents($delta->abs());
                $posting->journal('condition_rounding', $d['operation_key'], $d['accounting_date'], $d['reason'], $delta->isPositive() ? [['account' => '1200', 'debit' => $v, 'product_id' => $id], ['account' => '6900', 'credit' => $v]] : [['account' => '1200', 'credit' => $v, 'product_id' => $id], ['account' => '6900', 'debit' => $v]]);
            }

return ['id' => $id];
        });

        return $this->result($r, $result, 'Gjendja fizike u ndryshua me dy lëvizje të auditueshme.');
    }

    public function wooRetry(Request $r, int $id)
    {
        Gate::authorize('integrations.manage');
        $d = $r->validate(['event_id' => 'required|integer', 'reason' => 'required|string|min:5|max:1000']);
        $event = DB::table('integration_events')->where('id', $d['event_id'])->where('connection_id', $id)->firstOrFail();
        if (! in_array($event->status, ['pending', 'failed'], true)) {
            throw new \DomainException('Ngjarja është përpunuar.');
        }
        ProcessWooWebhook::dispatch($event->id);
        Audit::record('woo.retry_requested', 'integration_event', $event->id, ['reason' => $d['reason']]);

        return $this->result($r, ['id' => $event->id], 'Ngjarja u vendos në radhë për ripërpunim.');
    }

    public function wooCredentials(Request $r, int $id)
    {
        Gate::authorize('integrations.manage');
        $d = $r->validate(['consumer_key' => 'required|string|max:255', 'consumer_secret' => 'required|string|max:255', 'webhook_secret' => 'required|string|min:20|max:255', 'reason' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($id, $d) {
            DB::table('integration_connections')->where('id', $id)->where('type', 'woocommerce')->lockForUpdate()->firstOrFail();
            $credentials = $d;
            unset($credentials['reason']);
            DB::table('integration_connections')->where('id', $id)->update(['credentials' => Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR)), 'status' => 'unconfigured', 'last_error' => null, 'updated_at' => now()]);
            Audit::record('woo.credentials_rotated', 'integration_connection', $id, ['reason' => $d['reason']]);
        });

        return $this->result($r, ['id' => $id], 'Kredencialet u ruajtën të enkriptuara.');
    }

    public function supplierReturn(Request $r, int $id, Posting $posting, Stock $stock)
    {
        Gate::authorize('purchasing.manage');
        $d = $r->validate(['operation_key' => 'required|string|min:8|max:100', 'reference' => 'required|string|max:100', 'returned_on' => 'required|date', 'reason' => 'required|string|min:5|max:1000', 'condition' => 'required|in:sellable,quarantine,damaged', 'lines' => 'required|array|min:1', 'lines.*.purchase_line_id' => 'required|integer|distinct', 'lines.*.quantity' => 'required|string|regex:/^\d{1,12}(\.\d{1,6})?$/']);
        $result = $posting->once('supplier.return', $d + ['purchase_order_id' => $id], function () use ($id, $d, $posting, $stock) {
            $posting->configured();
            $order = DB::table('purchase_orders')->where('id', $id)->lockForUpdate()->firstOrFail();
            $items = [];
            $rows = [];
            $claim = Posting::decimal('0');
            $inputs = $d['lines'];
            usort($inputs, fn ($a, $b) => (int) $a['purchase_line_id'] <=> (int) $b['purchase_line_id']);
            foreach ($inputs as $input) {
                $line = DB::table('purchase_lines')->where('id', $input['purchase_line_id'])->where('purchase_order_id', $id)->lockForUpdate()->firstOrFail();
                $q = Posting::decimal($input['quantity']);
                $returned = Posting::decimal((string) DB::table('supplier_return_lines')->where('purchase_line_id', $line->id)->sum('quantity'));
                if (! $q->isPositive() || $q->plus($returned)->compareTo($line->received_quantity) > 0) {
                    throw new \DomainException('Kthimi tejkalon sasinë e pranuar nga furnitori.');
                }
                $move = $stock->move($line->product_id, (string) $q->negated(), null, $d['condition'], 'supplier_return', 'supplier_return', $d['operation_key'], $d['reason'], $d['returned_on']);
                $value = Posting::decimal($move->ledgerMovementValue())->negated();
                $claim = $claim->plus($value);
                $items[] = ['purchase_line_id' => $line->id, 'product_id' => $line->product_id, 'quantity' => (string) $q, 'cost_value' => (string) Posting::decimal($move->movementValue)->negated()];
                $rows[] = ['account' => '1310', 'debit' => (string) $value, 'party_id' => $order->supplier_id];
                $rows[] = ['account' => '1200', 'credit' => (string) $value, 'product_id' => $line->product_id];
            }
            $return = DB::table('supplier_returns')->insertGetId(['purchase_order_id' => $id, 'supplier_id' => $order->supplier_id, 'reference' => $d['reference'], 'returned_on' => $d['returned_on'], 'reason' => $d['reason'], 'claim_value' => Posting::cents($claim), 'operation_key' => $d['operation_key'], 'user_id' => auth()->id(), 'created_at' => now()]);
            foreach ($items as $line) {
                DB::table('supplier_return_lines')->insert(['supplier_return_id' => $return, ...$line]);
            }
            if ($claim->isPositive()) {
                $posting->journal('supplier_return',$return,$d['returned_on'],$d['reason'],$rows);
            } Audit::record('supplier.returned','supplier_return',$return,['claim_account' => '1310']);

            return ['id' => $return];
        });

        return $this->result($r,$result,'Mallrat u kthyen. Krediti i furnitorit rakordohet veçmas në llogarinë 1310.');
    }
}
