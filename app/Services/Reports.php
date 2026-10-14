<?php

declare(strict_types=1);

namespace App\Services;

use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Reports
{
    public function ledger(array $filters = []): array
    {
        $q = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('accounts as a', 'a.id', '=', 'l.account_id');
        if (! empty($filters['from'])) {
            $q->where('j.accounting_date', '>=', $filters['from']);
        } if (! empty($filters['to'])) {
            $q->where('j.accounting_date', '<=', $filters['to']);
        } if (! empty($filters['channel'])) {
            $q->where('l.channel', $filters['channel']);
        } if (! empty($filters['product_id'])) {
            $q->where('l.product_id', $filters['product_id']);
        }

        return $q->selectRaw('a.id, a.code, a.name, a.type, COALESCE(SUM(l.debit),0) debit, COALESCE(SUM(l.credit),0) credit')->groupBy('a.id', 'a.code', 'a.name', 'a.type')->orderBy('a.code')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function metrics(array $filters = []): array
    {
        $rows = $this->ledger($filters);
        $revenue = Posting::decimal('0');
        $cogs = Posting::decimal('0');
        $operating = Posting::decimal('0');
        $advertising = Posting::decimal('0');
        $delivery = Posting::decimal('0');
        $cash = Posting::decimal('0');
        $ar = Posting::decimal('0');
        $ap = Posting::decimal('0');
        foreach ($rows as $r) {
            $debit = Posting::decimal((string) $r['debit']);
            $credit = Posting::decimal((string) $r['credit']);
            if ($r['type'] === 'revenue') {
                $revenue = $revenue->plus($credit->minus($debit));
            }
            if ($r['code'] === '5000') {
                $cogs = $cogs->plus($debit->minus($credit));
            } elseif ($r['type'] === 'expense') {
                $operating = $operating->plus($debit->minus($credit));
            }
            if ($r['code'] === '6000') {
                $advertising = $advertising->plus($debit->minus($credit));
            } if ($r['code'] === '5100') {
                $delivery = $delivery->plus($debit->minus($credit));
            }
            if (in_array($r['code'], ['1000', '1010'], true)) {
                $cash = $cash->plus($debit->minus($credit));
            } if ($r['code'] === '1100') {
                $ar = $ar->plus($debit->minus($credit));
            } if ($r['code'] === '2100') {
                $ap = $ap->plus($credit->minus($debit));
            }
        }
        $value = Posting::decimal('0');
        $ar = Posting::decimal('0');
        $ap = Posting::decimal('0');
        foreach ($this->ledger() as $current) {
            $balance = Posting::decimal((string) $current['debit'])->minus((string) $current['credit']);
            if (in_array($current['code'], ['1200', '1210'], true)) {
                $value = $value->plus($balance);
            } if ($current['code'] === '1100') {
                $ar = $ar->plus($balance);
            } if ($current['code'] === '2100') {
                $ap = $ap->minus($balance);
            }
        }
        $gross = $revenue->minus($cogs);
        $profit = $gross->minus($operating);

        return array_map(fn ($d) => Posting::cents($d), ['revenue' => $revenue, 'gross_profit' => $gross, 'operating_profit' => $profit, 'operating_expenses' => $operating, 'advertising' => $advertising, 'delivery' => $delivery, 'cash_flow' => $cash, 'receivable' => $ar, 'payable' => $ap, 'inventory_value' => $value]);
    }

    public function export(string $report, array $filters): array
    {
        return match ($report) {
            'trial-balance' => $this->ledger($filters),
            'profit-loss' => array_values(array_filter($this->ledger($filters), fn ($r) => in_array($r['type'], ['revenue', 'expense'], true))),
            'products' => $this->products($filters),'monthly' => $this->monthly($filters),'budget' => $this->budgets($filters),
            'cash-flow' => [$this->metrics($filters)],
            'stock' => DB::table('stock_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')->select('p.sku', 'p.name', 'b.condition', 'b.on_hand', 'b.reserved', 'b.value')->orderBy('p.sku')->get()->map(fn ($r) => (array) $r)->all(),
            'journal' => $this->query($filters)->select('j.accounting_date', 'j.reference', 'j.description', 'a.code', 'l.debit', 'l.credit', 'l.party_id', 'l.product_id', 'l.channel')->orderBy('j.id')->get()->map(fn ($r) => (array) $r)->all(),
            'receivables','payables' => $this->query()->leftJoin('parties as p', 'p.id', '=', 'l.party_id')->where('a.code', $report === 'receivables' ? '1100' : '2100')->selectRaw('l.party_id,p.kind,p.name,SUM(l.debit) debit,SUM(l.credit) credit')->groupBy('l.party_id', 'p.kind', 'p.name')->get()->map(fn ($r) => (array) $r)->all(),
            'stock-movements' => DB::table('stock_movements')->whereBetween('occurred_at', [$filters['from'] ?? '1900-01-01', ($filters['to'] ?? '9999-12-31').' 23:59:59'])->when(! empty($filters['product_id']), fn ($q) => $q->where('product_id', $filters['product_id']))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            default => throw new \DomainException('Raporti nuk njihet.'),
        };
    }

    private function query(array $filters = [])
    {
        $q = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('accounts as a', 'a.id', '=', 'l.account_id');
        if (! empty($filters['from'])) {
            $q->where('j.accounting_date', '>=', $filters['from']);
        } if (! empty($filters['to'])) {
            $q->where('j.accounting_date', '<=', $filters['to']);
        } if (! empty($filters['channel'])) {
            $q->where('l.channel', $filters['channel']);
        } if (! empty($filters['product_id'])) {
            $q->where('l.product_id', $filters['product_id']);
        }

        return $q;
    }

    public function products(array $filters = []): array
    {
        $rows = $this->query($filters)->join('products as p', 'p.id', '=', 'l.product_id')->whereIn('a.code', ['4000', '5000'])->selectRaw("p.id,p.sku,p.name,SUM(CASE WHEN a.code='4000' THEN l.credit-l.debit ELSE 0 END) revenue,SUM(CASE WHEN a.code='5000' THEN l.debit-l.credit ELSE 0 END) cost")->groupBy('p.id', 'p.sku', 'p.name')->orderByDesc('revenue')->get();

        return $rows->map(function ($r) {
            $margin = Posting::decimal($r->revenue)->minus($r->cost);
            $revenue = Posting::decimal($r->revenue);

            return [...(array) $r, 'gross_profit' => Posting::cents($margin), 'margin_percent' => $revenue->isZero() ? null : (string) $margin->multipliedBy('100')->dividedBy($revenue, 2, RoundingMode::HALF_UP)];
        })->all();
    }

    public function monthly(array $filters = []): array
    {
        $end = CarbonImmutable::parse($filters['to'] ?? now(config('techpoint.timezone'))->toDateString())->startOfMonth();
        $result = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = $end->subMonths($i);
            $m = $this->metrics(array_replace($filters, ['from' => $month->toDateString(), 'to' => $month->endOfMonth()->toDateString()]));
            $result[] = ['month' => $month->format('Y-m'), 'revenue' => $m['revenue'], 'gross_profit' => $m['gross_profit'], 'operating_profit' => $m['operating_profit']];
        }
        $max = Posting::decimal('0');
        foreach ($result as $r) {
            foreach (['revenue', 'operating_profit'] as $key) {
                $v = Posting::decimal($r[$key])->abs();
                if ($v->compareTo($max) > 0) {
                    $max = $v;
                }
            }
        }

        return array_map(function ($r) use ($max) {
            foreach (['revenue', 'operating_profit'] as $key) {
                $r[$key.'_height'] = $max->isZero() ? '0' : (string) Posting::decimal($r[$key])->abs()->multipliedBy('100')->dividedBy($max, 2, RoundingMode::HALF_UP);
            }

            return $r;
        }, $result);
    }

    public function activity(array $filters = []): array
    {
        $from = $filters['from'] ?? '1900-01-01';
        $to = $filters['to'] ?? '9999-12-31';
        $sales = DB::table('sales')->whereBetween('ordered_on', [$from, $to]);
        if (! empty($filters['channel'])) {
            $sales->whereIn('channel_id', DB::table('sales_channels')->where('code', $filters['channel'])->select('id'));
        } if (! empty($filters['product_id'])) {
            $sales->whereIn('id', DB::table('sale_lines')->where('product_id', $filters['product_id'])->select('sale_id'));
        }
        $s = $sales->selectRaw('COUNT(*) orders,COALESCE(SUM(gross_total),0) ordered_gross')->first();

        $credits = DB::table('sale_returns as r')->join('return_lines as l', 'l.sale_return_id', '=', 'r.id')->join('fulfillment_lines as f', 'f.id', '=', 'l.fulfillment_line_id')->join('sales as s', 's.id', '=', 'r.sale_id')->join('sales_channels as c', 'c.id', '=', 's.channel_id')->whereBetween('r.credited_on', [$from, $to]);
        $refunds = DB::table('refunds as r')->join('sale_returns as t', 't.id', '=', 'r.sale_return_id')->join('sales as s', 's.id', '=', 't.sale_id')->join('sales_channels as c', 'c.id', '=', 's.channel_id')->whereBetween('r.refunded_on', [$from, $to]);
        if (! empty($filters['channel'])) {
            $credits->where('c.code', $filters['channel']);
            $refunds->where('c.code', $filters['channel']);
        }
        if (! empty($filters['product_id'])) {
            $credits->where('f.product_id', $filters['product_id']);
            $refunds->whereIn('s.id', DB::table('sale_lines')->where('product_id', $filters['product_id'])->select('sale_id'));
        }
        $credit = $credits->selectRaw('COALESCE(SUM(l.net_total+l.tax_total),0) amount')->first();

        return ['orders' => $s->orders, 'average_order' => $s->orders ? (string) Posting::decimal($s->ordered_gross)->dividedBy((string) $s->orders, 2, RoundingMode::HALF_UP) : '0.00', 'credits' => Posting::cents($credit->amount), 'refunds' => Posting::cents((string) $refunds->sum('r.amount'))];
    }

    public function cash(array $filters = []): array
    {
        $r = $this->query($filters)->whereIn('a.code', ['1000', '1010'])->where('j.source_type', '!=', 'transfer')->selectRaw('COALESCE(SUM(l.debit),0) inflow, COALESCE(SUM(l.credit),0) outflow')->first();

        return ['inflow' => $r->inflow, 'outflow' => $r->outflow];
    }

    public function budgets(array $filters = []): array
    {
        $q = DB::table('budgets as b')->join('accounts as a', 'a.id', '=', 'b.account_id')->select('b.*', 'a.code', 'a.name');
        if (! empty($filters['from'])) {
            $q->where('b.month', '>=', CarbonImmutable::parse($filters['from'])->startOfMonth()->toDateString());
        } if (! empty($filters['to'])) {
            $q->where('b.month', '<=', $filters['to']);
        }

        return $q->orderBy('month')->get()->map(function ($r) {
            $end = CarbonImmutable::parse($r->month)->endOfMonth()->toDateString();
            $row = $this->query(['from' => $r->month, 'to' => $end])->where('a.id', $r->account_id)->selectRaw('COALESCE(SUM(l.debit-l.credit),0) actual')->first();

            return ['month' => $r->month, 'account' => $r->code, 'name' => $r->name, 'budget' => $r->amount, 'actual' => $row->actual, 'remaining' => Posting::cents(Posting::decimal($r->amount)->minus($row->actual))];
        })->all();
    }
}
