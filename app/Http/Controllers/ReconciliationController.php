<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Money;
use App\Domain\Purchasing\LandedCostAllocator;
use App\Services\Audit;
use App\Services\Finance;
use App\Services\Posting;
use App\Services\Selling;
use App\Services\Stock;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ReconciliationController extends Controller
{
    public function failed(Request $r, int $id)
    {
        Gate::authorize('sales.manage');
        $d = $r->validate(['reason' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($id, $d) {
            $delivery = DB::table('deliveries')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($delivery->status !== 'in_transit') {
                throw new \DomainException('Dërgesa nuk është në transport.');
            } DB::table('deliveries')->where('id', $id)->update(['status' => 'failed', 'updated_at' => now()]);
            $f = DB::table('fulfillments')->where('id', $delivery->fulfillment_id)->first();
            DB::table('sales')->where('id', $f->sale_id)->update(['delivery_status' => 'failed', 'updated_at' => now()]);
            Audit::record('delivery.failed', 'delivery', $id, ['reason' => $d['reason']]);
        });

        return back()->with('success', 'Dërgesa u shënua e dështuar; stoku nuk u rikthye automatikisht.');
    }

    public function returnedParcel(Request $r, int $id, Posting $posting, Stock $stock)
    {
        Gate::authorize('inventory.manage');
        $d = $r->validate(['operation_key' => 'required|string|min:8|max:100', 'received_on' => 'required|date', 'reason' => 'required|string|min:5|max:1000', 'lines' => 'required|array|min:1', 'lines.*.fulfillment_line_id' => 'required|integer|distinct', 'lines.*.quantity' => 'required|string|regex:/^\d{1,12}(\.\d{1,6})?$/']);
        $result = $posting->once('parcel.return', $d + ['delivery_id' => $id], function () use ($id, $d, $posting, $stock) {
            $delivery = DB::table('deliveries')->where('id', $id)->lockForUpdate()->firstOrFail();
            $f = DB::table('fulfillments')->where('id', $delivery->fulfillment_id)->lockForUpdate()->firstOrFail();
            if ($f->recognized || ! in_array($delivery->status, ['failed', 'returning'], true)) {
                throw new \DomainException('Për shitje të njohur përdorni kreditimin dhe kthimin e autorizuar.');
            } $rows = [];
            foreach ($d['lines'] as $input) {
                $l = DB::table('fulfillment_lines')->where('id', $input['fulfillment_line_id'])->where('fulfillment_id', $f->id)->lockForUpdate()->firstOrFail();
                $q = Posting::decimal($input['quantity']);
                if (! $q->isPositive() || $q->plus($l->returned_quantity)->compareTo($l->quantity) > 0) {
                    throw new \DomainException('Sasia tejkalon mallrat e dërguara.');
                }
                $last = $q->plus($l->returned_quantity)->compareTo($l->quantity) === 0;
                $cost = $last ? Posting::decimal($l->cost_value)->minus($l->returned_cost) : Posting::decimal($l->cost_value)->multipliedBy($q)->dividedBy($l->quantity, 8, RoundingMode::HALF_UP);
                $ledger = $last ? Posting::decimal($l->ledger_cost)->minus($l->returned_ledger_cost) : Posting::decimal($l->ledger_cost)->multipliedBy($q)->dividedBy($l->quantity, 2, RoundingMode::HALF_UP);
                $move = $stock->move($l->product_id, (string) $q, (string) $cost, 'sellable', 'parcel_return', 'parcel_return', $d['operation_key'].':'.$l->id, $d['reason'], $d['received_on']);
                $inventory = Posting::decimal($move->ledgerMovementValue());
                $rows[] = ['account' => '1200', 'debit' => (string) $inventory];
                $rows[] = ['account' => '1210', 'credit' => (string) $ledger];
                $difference = $ledger->minus($inventory);
                if ($difference->isPositive()) {
                    $rows[] = ['account' => '6900', 'debit' => (string) $difference];
                } elseif ($difference->isNegative()) {
                    $rows[] = ['account' => '6900', 'credit' => (string) $difference->negated()];
                }
                DB::table('fulfillment_lines')->where('id', $l->id)->update(['returned_quantity' => (string) $q->plus($l->returned_quantity), 'returned_cost' => (string) $cost->plus($l->returned_cost), 'returned_ledger_cost' => (string) $ledger->plus($l->returned_ledger_cost)]);
            }
            if ($rows) {
                $posting->journal('parcel_return', $d['operation_key'], $d['received_on'], $d['reason'], $rows);
            } $remaining = DB::table('fulfillment_lines')->where('fulfillment_id', $f->id)->whereColumn('returned_quantity', '<', 'quantity')->exists();
            DB::table('deliveries')->where('id', $id)->update(['status' => $remaining ? 'returning' : 'returned', 'updated_at' => now()]);
            if (! $remaining) {
                DB::table('fulfillments')->where('id', $f->id)->update(['status' => 'returned', 'updated_at' => now()]);
            }

            return ['id' => $id];
        });

        return $r->expectsJson() ? response()->json($result) : back()->with('success', 'Mallrat e kthyer u pranuan pa krijuar të ardhura të dyfishta.');
    }

    public function collect(Request $r, int $id, Posting $posting)
    {
        Gate::authorize('finance.manage');
        $d = $r->validate(['operation_key' => 'required|string|min:8|max:100', 'amount' => 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/', 'accounting_date' => 'required|date', 'reference' => 'required|string|max:100']);
        $result = $posting->once('courier.collect', $d + ['delivery_id' => $id], function () use ($id, $d, $posting) {
            $delivery = DB::table('deliveries')->where('id', $id)->lockForUpdate()->firstOrFail();
            $f = DB::table('fulfillments')->where('id', $delivery->fulfillment_id)->first();
            if (! $f->recognized || $delivery->status !== 'delivered' || ! $delivery->courier_id) {
                throw new \DomainException('Arkëtimi nga korrieri kërkon dërgesë të dorëzuar dhe korrier të caktuar.');
            }
            $s = DB::table('sales')->where('id', $f->sale_id)->lockForUpdate()->firstOrFail();
            $posting->fiscalGate($s->id, $d);
            $sum = DB::table('fulfillment_lines')->where('fulfillment_id', $f->id)->selectRaw('SUM(net_total+tax_total) gross')->first();
            $gross = Posting::decimal((string) $sum->gross)->plus($f->delivery_charge)->plus($f->delivery_tax);
            $amount = Money::of($d['amount']);
            $already = Posting::decimal((string) DB::table('payments')->where('sale_id', $s->id)->sum('amount'));
            $credits = Posting::decimal((string) DB::table('sale_returns')->where('sale_id', $s->id)->sum('gross_total'));
            $refunds = Posting::decimal((string) DB::table('refunds')->join('sale_returns', 'sale_returns.id', '=', 'refunds.sale_return_id')->where('sale_returns.sale_id', $s->id)->sum('refunds.amount'));
            $open = Posting::decimal(app(Selling::class)->recognizedGross($s->id))->minus($credits)->minus($already)->plus($refunds);
            if (! $amount->decimal()->isPositive() || Posting::decimal($delivery->collected)->plus($amount->decimal())->compareTo($gross) > 0 || $amount->decimal()->compareTo($open) > 0) {
                throw new \DomainException('Arkëtimi tejkalon shumën e hapur.');
            }
            $account = DB::table('accounts')->where('code', '1100')->value('id');
            $payment = DB::table('payments')->insertGetId(['kind' => 'courier_collection', 'sale_id' => $s->id, 'account_id' => $account, 'amount' => $amount->amount(), 'method' => 'cod', 'reference' => $d['reference'], 'operation_key' => $d['operation_key'], 'paid_on' => $d['accounting_date'], 'user_id' => auth()->id(), 'created_at' => now()]);
            $posting->journal('courier_collection', $payment, $d['accounting_date'], 'Arkëtim nga korrieri', [['account' => '1100', 'debit' => $amount->amount(), 'party_id' => $delivery->courier_id], ['account' => '1100', 'credit' => $amount->amount(), 'party_id' => $s->customer_id]]);
            DB::table('deliveries')->where('id', $id)->update(['collected' => (string) Posting::decimal($delivery->collected)->plus($amount->decimal()), 'updated_at' => now()]);
            DB::table('sales')->where('id', $s->id)->update(['payment_status' => 'collected_by_courier', 'updated_at' => now()]);

            return ['id' => $payment];
        });

        return $r->expectsJson() ? response()->json($result) : back()->with('success', 'Arkëtimi u regjistrua si kërkesë ndaj korrierit.');
    }

    public function settlement(Request $r, Posting $posting, Finance $finance)
    {
        Gate::authorize('finance.manage');
        $d = $r->validate(['operation_key' => 'required|string|min:8|max:100', 'courier_id' => 'required|integer|exists:parties,id', 'reference' => 'required|string|max:100', 'settled_on' => 'required|date', 'account_id' => 'required|integer|exists:accounts,id', 'fees' => 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/', 'remitted' => 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/', 'lines' => 'required|array|min:1', 'lines.*.delivery_id' => 'required|integer|distinct', 'lines.*.amount' => 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/']);
        $result = $posting->once('courier.settle', $d, function () use ($d, $posting, $finance) {
            $finance->cashAccount($d['account_id']);
            $expected = Posting::decimal('0');
            $weights = [];
            foreach ($d['lines'] as $line) {
                $delivery = DB::table('deliveries')->where('id', $line['delivery_id'])->lockForUpdate()->firstOrFail();
                $prior = Posting::decimal((string) DB::table('courier_settlement_lines')->where('delivery_id', $delivery->id)->sum('cleared'));
                $amount = Money::of($line['amount']);
                if ((int) $delivery->courier_id !== (int) $d['courier_id'] || ! $amount->decimal()->isPositive() || $prior->plus($amount->decimal())->compareTo($delivery->collected) > 0) {
                    throw new \DomainException('Rreshtat nuk përputhen me arkëtimet e hapura të korrierit.');
                } $weights[$delivery->id] = $amount->amount();
                $expected = $expected->plus($amount->decimal());
            }
            $fees = Money::of($d['fees']);
            $remitted = Money::of($d['remitted']);
            $clearance = $fees->add($remitted);
            if ($clearance->isZero() || $clearance->decimal()->compareTo($expected) > 0) {
                throw new \DomainException('Shuma e transferuar dhe tarifat tejkalojnë arkëtimet e zgjedhura.');
            }
            $difference = $expected->minus($clearance->decimal());
            $id = DB::table('courier_settlements')->insertGetId(['courier_id' => $d['courier_id'], 'reference' => $d['reference'], 'settled_on' => $d['settled_on'], 'account_id' => $d['account_id'], 'collected' => (string) $expected, 'fees' => $fees->amount(), 'remitted' => $remitted->amount(), 'difference' => (string) $difference, 'delivery_ids' => json_encode(array_keys($weights), JSON_THROW_ON_ERROR), 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $alloc = (new LandedCostAllocator)->allocate($clearance, $weights);
            foreach ($weights as $delivery => $amount) {
                DB::table('courier_settlement_lines')->insert(['courier_settlement_id' => $id, 'delivery_id' => $delivery, 'expected' => $amount, 'cleared' => $alloc[$delivery]->amount()]);
            }
            $posting->journal('courier_settlement', $id, $d['settled_on'], 'Transferim nga korrieri', [['account_id' => $d['account_id'], 'debit' => $remitted->amount()], ['account' => '5100', 'debit' => $fees->amount()], ['account' => '1100', 'credit' => $clearance->amount(), 'party_id' => $d['courier_id']]]);

            return ['id' => $id, 'difference' => (string) $difference];
        });

        return $r->expectsJson() ? response()->json($result) : back()->with('success', 'Transferimi u rakordua; diferenca e pashlyer mbetet e arkëtueshme.');
    }

    public function bankMatch(Request $r, int $id, Posting $posting)
    {
        Gate::authorize('finance.reconcile');
        $d = $r->validate(['operation_key' => 'required|string|min:8|max:100', 'journal_id' => 'required|integer|exists:journals,id', 'reason' => 'required|string|min:5|max:1000']);
        $result = $posting->once('bank.match', $d + ['bank_id' => $id], function () use ($id, $d) {
            $b = DB::table('bank_transactions')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($b->status !== 'unmatched') {
                throw new \DomainException('Transaksioni është rakorduar.');
            }
            if (DB::table('bank_transactions')->where('account_id', $b->account_id)->where('journal_id', $d['journal_id'])->exists()) {
                throw new \DomainException('Regjistrimi është rakorduar me një transaksion tjetër.');
            }
            $l = DB::table('journal_lines')->where('journal_id', $d['journal_id'])->where('account_id', $b->account_id)->selectRaw('COALESCE(SUM(debit-credit),0) amount')->first();
            if (Posting::decimal((string) $l->amount)->compareTo($b->amount) !== 0) {
                throw new \DomainException('Shuma bankare nuk përputhet me ditarin.');
            }
            DB::table('bank_transactions')->where('id', $id)->update(['journal_id' => $d['journal_id'], 'status' => 'matched', 'updated_at' => now()]);
            Audit::record('bank.matched', 'bank_transaction', $id, ['journal_id' => $d['journal_id'], 'reason' => $d['reason']]);

            return ['id' => $id];
        });

        return $r->expectsJson() ? response()->json($result) : back()->with('success', 'Transaksioni bankar u rakordua.');
    }

    public function transfer(Request $r, Finance $finance)
    {
        Gate::authorize('finance.manage');
        $d = $r->validate(['operation_key' => 'required|string|min:8|max:100', 'from_account_id' => 'required|integer|exists:accounts,id', 'to_account_id' => 'required|integer|exists:accounts,id', 'amount' => 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/', 'accounting_date' => 'required|date', 'reason' => 'required|string|min:5|max:1000']);
        $result = $finance->transfer($d);

        return $r->expectsJson() ? response()->json($result) : back()->with('success', 'Transferimi u regjistrua.');
    }
}
