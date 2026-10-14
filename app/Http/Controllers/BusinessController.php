<?php

namespace App\Http\Controllers;

use App\Integrations\WooCommerce\WooSyncService;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit;
use App\Services\Finance;
use App\Services\Posting;
use App\Services\Purchasing;
use App\Services\Reports;
use App\Services\Returns;
use App\Services\Selling;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

final class BusinessController extends Controller
{
    private const MODULES = [
        'products' => ['products', 'Produktet', 'inventory.view', 'inventory.manage'],
        'suppliers' => ['parties', 'Furnitorët', 'purchasing.view', 'purchasing.manage'],
        'customers' => ['parties', 'Klientët', 'sales.view', 'sales.manage'],
        'couriers' => ['parties', 'Korrierët', 'finance.view', 'finance.manage'],
        'purchases' => ['purchase_orders', 'Blerjet dhe importet', 'purchasing.view', 'purchasing.manage'],
        'sales' => ['sales', 'Shitjet', 'sales.view', 'sales.manage'],
        'returns' => ['sale_returns', 'Kthimet', 'sales.view', 'sales.refund'],
        'bills' => ['bills', 'Faturat dhe shpenzimet', 'finance.view', 'finance.manage'],
        'accounts' => ['accounts', 'Plani kontabël', 'accounting.view', 'accounting.post'],
        'periods' => ['accounting_periods', 'Periudhat kontabël', 'accounting.view', 'accounting.close'],
        'journals' => ['journals', 'Ditari kontabël', 'accounting.view', 'accounting.post'],
        'stock' => ['stock_movements', 'Lëvizjet e stokut', 'inventory.view', 'inventory.manage'],
        'deliveries' => ['deliveries', 'Dërgesat', 'sales.view', 'finance.manage'],
        'settlements' => ['courier_settlements', 'Rakordimi me korrierët', 'finance.view', 'finance.manage'],
        'bank' => ['bank_transactions', 'Rakordimi bankar', 'finance.view', 'finance.manage'],
        'budgets' => ['budgets', 'Buxheti', 'reports.view', 'finance.manage'],
        'recurring' => ['recurring_expenses', 'Shpenzime periodike', 'finance.view', 'finance.manage'],
        'channels' => ['sales_channels', 'Kanalet e shitjes', 'sales.view', 'settings.manage'],
        'integrations' => ['integration_connections', 'Integrimet', 'integrations.view', 'integrations.manage'],
        'fiscal' => ['fiscal_documents', 'Dokumentet fiskale', 'integrations.view', 'integrations.manage'],
        'users' => ['users', 'Përdoruesit', 'users.manage', 'users.manage'],
        'roles' => ['roles', 'Rolet', 'users.manage', 'users.manage'],
        'audit' => ['audit_events', 'Gjurmët e auditimit', 'audit.view', null],
        'counts' => ['stock_counts', 'Numërimet e stokut', 'inventory.view', 'inventory.manage'],
        'categories' => ['categories', 'Kategoritë', 'inventory.view', 'inventory.manage'],
        'drafts' => ['journal_drafts', 'Draftet kontabël', 'accounting.view', 'accounting.post'],
        'supplier-returns' => ['supplier_returns', 'Kthimet te furnitorët', 'purchasing.view', null],
    ];

    public function dashboard(Request $r, Reports $reports)
    {
        if (!Gate::allows('dashboard.view')) { foreach (['sales.view'=>'/sales','inventory.view'=>'/products','finance.view'=>'/bills','reports.view'=>'/reports'] as $permission=>$path) if (Gate::allows($permission)) return redirect($path); return redirect('/settings/profile'); }
        Gate::authorize('dashboard.view');
        $filters = $this->filters($r);
        $from = $filters['from'] ?? now(config('techpoint.timezone'))->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now(config('techpoint.timezone'))->toDateString();
        $metrics = $reports->metrics($filters + ['from' => $from, 'to' => $to]);
        $labels = ['revenue' => 'Të ardhurat pa TVSH', 'gross_profit' => 'Fitimi bruto', 'operating_profit' => 'Fitimi operativ', 'operating_expenses' => 'Shpenzimet operative', 'advertising' => 'Reklamimi', 'delivery' => 'Posta dhe transporti', 'cash_flow' => 'Fluksi i parasë', 'receivable' => 'Llogaritë e arkëtueshme', 'payable' => 'Llogaritë e pagueshme', 'inventory_value' => 'Vlera e inventarit qendror'];
        $warnings = ['Fitimi neto pas tatimit kërkon plotësimin dhe validimin e regjistrimeve nga kontabilisti.', 'Integrimi fiskal është në pritje të një ofruesi të verifikuar.'];
        if (! DB::table('settings')->where('key', 'accounting')->exists()) {
            $warnings[] = 'Përfundoni konfigurimin tatimor dhe hapni një periudhë kontabël përpara postimit.';
        }

        return Inertia::render('business/Dashboard', ['title' => 'Përmbledhja', 'metrics' => collect($metrics)->map(fn ($v, $k) => ['key' => $k, 'label' => $labels[$k], 'value' => $v])->values(), 'lowStock' => DB::table('products as p')->leftJoin('stock_balances as b', fn ($j) => $j->on('b.product_id', '=', 'p.id')->where('b.condition', 'sellable'))->where('p.active', true)->whereRaw('COALESCE(b.on_hand,0)-COALESCE(b.reserved,0)<=p.minimum_stock')->select('p.sku', 'p.name', 'p.minimum_stock', DB::raw('COALESCE(b.on_hand,0) on_hand'))->limit(10)->get(), 'warnings' => $warnings, 'filters' => $filters + ['from' => $from, 'to' => $to], 'monthly' => $reports->monthly($filters + ['from' => $from, 'to' => $to]), 'topProducts' => array_slice($reports->products($filters + ['from' => $from, 'to' => $to]), 0, 10), 'activity' => $reports->activity($filters + ['from' => $from, 'to' => $to]), 'cash' => $reports->cash($filters + ['from' => $from, 'to' => $to]), 'channels' => DB::table('sales_channels')->where('active', true)->select('code', 'name')->get(), 'products' => $this->products(), 'comparisons' => ['previous_month' => $reports->metrics(array_replace($filters, ['from' => CarbonImmutable::parse($from)->subMonthNoOverflow()->toDateString(), 'to' => CarbonImmutable::parse($to)->subMonthNoOverflow()->toDateString()])), 'previous_year' => $reports->metrics(array_replace($filters, ['from' => CarbonImmutable::parse($from)->subYearNoOverflow()->toDateString(), 'to' => CarbonImmutable::parse($to)->subYearNoOverflow()->toDateString()]))], 'today' => $reports->metrics(['from' => now(config('techpoint.timezone'))->toDateString(), 'to' => now(config('techpoint.timezone'))->toDateString()])]);
    }

    public function index(Request $r, string $module)
    {
        [$table,$title,$view,$manage] = $this->definition($module);
        Gate::authorize($view);
        $q = DB::table($table);
        $fields = $this->fields($module);
        if (in_array($module, ['suppliers', 'customers', 'couriers'], true)) {
            $q->where('kind', match ($module) {
                'suppliers' => 'supplier','customers' => 'customer','couriers' => 'courier'
            });
        }
        if ($r->filled('q')) {
            $search = (string) $r->input('q');
            $column = match ($module) {
                'products','suppliers','customers','couriers','accounts','roles','channels','recurring','categories' => 'name','users' => 'email','stock' => 'reason','audit' => 'action','integrations' => 'name',default => 'reference'
            };
            if (! in_array($module, ['periods', 'fiscal', 'budgets', 'deliveries', 'bank'], true)) {
                $q->where($column, 'like', '%'.$search.'%');
            }
        }
        $records = $q->orderByDesc('id')->limit(200)->get();
        if ($module === 'stock' && ! Gate::allows('finance.view') && ! Gate::allows('purchasing.view')) {
            $records = $records->map(function ($record) {
                unset($record->value,$record->balance_value);

                return $record;
            });
        }
        if ($module === 'users') {
            $records = $records->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'active' => $u->active]);
        }
        if ($module === 'integrations') {
            $records = $records->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'base_url' => $v->base_url, 'status' => $v->status, 'last_success_at' => $v->last_success_at, 'last_error' => $v->last_error]);
        }
        if ($module === 'products') {
            foreach ($records as $p) {
                $b = DB::table('stock_balances')->where('product_id', $p->id)->where('condition', 'sellable')->first();
                $p->on_hand = $b?->on_hand ?? '0';
                $p->reserved = $b?->reserved ?? '0';
                if (! Gate::allows('finance.view') && ! Gate::allows('purchasing.view')) {
                    unset($p->purchase_price);
                }
            }
        }
        $keys = $records->isEmpty() ? array_column($fields, 'key') : array_keys((array) $records->first());
        $keys = array_values(array_filter($keys, fn ($k) => ! in_array($k, ['user_id', 'updated_at', 'password', 'remember_token', 'credentials', 'request_payload', 'details', 'notes', 'lines', 'delivery_ids'], true)));
        $labels = $this->labels();
        $columns = array_map(fn ($k) => ['key' => $k, 'label' => $labels[$k] ?? ucfirst(str_replace('_', ' ', $k))], array_slice($keys, 0, 9));
        if ($module==='products') $columns=array_map(fn ($k)=>['key'=>$k,'label'=>$labels[$k]??$k],['sku','name','barcode','selling_price','on_hand','reserved','minimum_stock','active']);

        return Inertia::render('business/Index', ['module' => $module, 'title' => $title, 'description' => 'Regjistra të ruajtur në bazën qendrore.', 'records' => $records, 'columns' => $columns, 'fields' => $fields, 'tools' => $this->tools($module), 'createAction' => $manage && $fields && Gate::allows($manage) ? match ($module) {
            'settlements' => '/courier-settlements','counts' => '/stock-counts','drafts' => '/journal-drafts',default => '/'.$module
        } : null, 'readOnly' => ! $manage || ! Gate::allows($manage), 'filters' => ['q' => $r->input('q', '')], 'detail' => in_array($module, ['products', 'purchases', 'sales', 'returns', 'bills', 'journals', 'integrations', 'fiscal', 'periods', 'bank', 'deliveries', 'roles', 'users', 'counts', 'drafts', 'recurring', 'supplier-returns'], true)]);
    }

    public function store(Request $r, string $module, Purchasing $purchasing, Selling $selling, Finance $finance, Posting $posting)
    {
        [$table,,$view,$permission] = $this->definition($module);
        abort_unless($permission, 405);
        Gate::authorize($permission);
        $data = $r->validate($this->rules($module));
        if ($module === 'purchases') {
            $id = $purchasing->create($data);
        } elseif ($module === 'sales') {
            $id = $selling->create($data)['id'];
        } elseif ($module === 'bills') {
            $id = $finance->bill($data)['id'];
        } elseif ($module === 'journals') {
            $id = $posting->once('manual.journal', $data, function () use ($data, $posting) {
                return ['id' => $posting->journal('manual', $data['operation_key'], $data['accounting_date'], $data['description'], $data['lines'])];
            })['id'];
        } else {
            $id = DB::transaction(function () use ($module, $table, $data) {
                unset($data['operation_key']);
                if ($module === 'products' && (! Posting::decimal($data['unit_conversion'])->isPositive() || Posting::decimal($data['tax_rate'])->compareTo('100') > 0)) {
                    throw new \DomainException('Konvertimi duhet të jetë pozitiv dhe TVSH deri 100%.');
                }
                if ($module === 'budgets' && substr($data['month'], 8, 2) !== '01') {
                    throw new \DomainException('Buxheti kërkon ditën e parë të muajit.');
                }
                if ($module === 'categories' && DB::table('categories')->where('name', $data['name'])->where('parent_id', $data['parent_id'] ?? null)->exists()) {
                    throw new \DomainException('Kategoria ekziston tashmë.');
                }
                if (in_array($module, ['suppliers', 'customers', 'couriers'], true)) {
                    $data['kind'] = match ($module) {
                        'suppliers' => 'supplier','customers' => 'customer','couriers' => 'courier'
                    };
                }
                if ($module === 'integrations') {
                    $data['type'] = 'woocommerce';
                    $data['credentials'] = Crypt::encryptString(json_encode(['consumer_key' => $data['consumer_key'], 'consumer_secret' => $data['consumer_secret'], 'webhook_secret' => $data['webhook_secret']], JSON_THROW_ON_ERROR));
                    unset($data['consumer_key'],$data['consumer_secret'],$data['webhook_secret']);
                    $data['status'] = 'unconfigured';
                }
                if ($module === 'users') {
                    $role = Role::findOrFail($data['role_id']);
                    unset($data['role_id']);
                    $u = User::create($data);
                    $u->forceFill(['email_verified_at' => now()])->save();
                    $u->roles()->attach($role);
                    Audit::record('user.created', 'user', $u->id);

                    return $u->id;
                }
                if ($module === 'periods' && DB::table('accounting_periods')->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists()) {
                    throw new \DomainException('Periudhat nuk mund të mbivendosen.');
                }
                if (! in_array($module, ['channels', 'categories'], true)) {
                    $data += ['created_at' => now(), 'updated_at' => now()];
                }
                $id = DB::table($table)->insertGetId($data);
                Audit::record($module.'.created', $module, $id);

                return $id;
            });
        }
        if ($r->expectsJson()) {
            return response()->json(['id' => $id], 201);
        }

        return redirect('/'.$module)->with('success', 'Regjistri u ruajt.');
    }

    public function show(Request $r, string $module, int $id)
    {
        [$table,$title,$view] = $this->definition($module);
        Gate::authorize($view);
        $record = DB::table($table)->where('id', $id)->firstOrFail();
        $lines = [];
        $related = [];
        $actions = [];
        if ($module === 'users') {
            $record = (object) ['id' => $record->id, 'name' => $record->name, 'email' => $record->email, 'active' => $record->active];
            $actions[] = $this->action('access', 'Ndrysho qasjen', "/users/$id/access", [$this->select('role_id', 'Roli', Role::all()->map(fn ($a) => ['value' => $a->id, 'label' => $a->label])->all()), $this->field('active', 'Llogari aktive', 'checkbox', (bool) $record->active), $this->field('reason', 'Arsyeja', 'textarea')]);
        }
        if ($module === 'roles' && $record->name !== 'owner') {
            $field = $this->field('permissions', 'Lejet e dhëna', 'permissions', Role::findOrFail($id)->permissions->pluck('name')->all());
            $field['options'] = array_map(fn ($name) => ['value' => $name, 'label' => $this->permissionLabel($name)], config('techpoint.permissions'));
            $actions[] = $this->action('permissions', 'Ndrysho lejet', "/roles/$id/permissions", [$field, $this->field('reason', 'Arsyeja', 'textarea')]);
        }
        if ($module === 'products') {
            $lines = DB::table('stock_movements')->where('product_id', $id)->orderByDesc('id')->limit(100)->get();
            if (! Gate::allows('finance.view') && ! Gate::allows('purchasing.view')) {
                unset($record->purchase_price);
                $lines = $lines->map(function ($m) {
                    unset($m->value,$m->balance_value);

                    return $m;
                });
            }
        }
        if ($module === 'purchases') {
            $lines = DB::table('purchase_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('purchase_order_id', $id)->select('l.*', 'p.name', 'p.sku')->get();
            $related = ['Kostot shtesë' => DB::table('landed_cost_components')->where('purchase_order_id', $id)->get(), 'Pranimet' => DB::table('purchase_receipts')->where('purchase_order_id', $id)->get()];
            if (Gate::allows('purchasing.manage')) {
                $actions[] = $this->action('cost', 'Shto kosto importi', "/purchases/$id/costs", $this->costFields());
                $actions[] = $this->action('receive', 'Konfirmo pranimin', "/purchases/$id/receive", [$this->field('reference', 'Numri i pranimit'), $this->field('received_on', 'Data e pranimit', 'date'), $this->repeat('lines', 'Produktet e pranuara', [$this->select('purchase_line_id', 'Rreshti i porosisë', $lines->map(fn ($l) => ['value' => $l->id, 'label' => $l->sku.' · '.$l->name])->all()), $this->field('quantity', 'Sasia', 'decimal')])]);
            }
        }
        if ($module === 'sales') {
            $lines = DB::table('sale_lines')->where('sale_id', $id)->get();
            $fulfillments = DB::table('fulfillments')->where('sale_id', $id)->get();
            $related = ['Dërgimet' => $fulfillments, 'Pagesat' => DB::table('payments')->where('sale_id', $id)->get(), 'Kreditimet' => DB::table('sale_returns')->where('sale_id', $id)->get()];
            if (Gate::allows('sales.manage')) {
                $actions[] = $this->action('dispatch', 'Konfirmo dërgimin', "/sales/$id/dispatch", [$this->field('dispatched_on', 'Data e dërgimit', 'date'), $this->select('courier_id', 'Korrieri', $this->parties('courier')), $this->field('tracking_reference', 'Referenca e gjurmimit'), $this->repeat('lines', 'Sasitë e dërguara', [$this->select('sale_line_id', 'Produkti', $lines->map(fn ($l) => ['value' => $l->id, 'label' => $l->sku.' · '.$l->name])->all()), $this->field('quantity', 'Sasia', 'decimal')])]);
                $actions[] = $this->action('cancel', 'Anulo rezervimet e mbetura', "/sales/$id/cancel", [$this->field('reason', 'Arsyeja', 'textarea')]);
                $actions[] = $this->action('payment', 'Regjistro pagesë', "/sales/$id/payments", $this->paymentFields());
                foreach ($fulfillments as $f) {
                    if ($f->status === 'dispatched') {
                        $actions[] = $this->action('deliver'.$f->id, 'Konfirmo dorëzimin #'.$f->id, "/fulfillments/$f->id/deliver", [$this->field('delivered_on', 'Data e dorëzimit', 'date')]);
                    }
                }
            } if (Gate::allows('sales.refund')) {
                $eligible = DB::table('fulfillment_lines as l')->join('fulfillments as f', 'f.id', '=', 'l.fulfillment_id')->join('products as p', 'p.id', '=', 'l.product_id')->where('f.sale_id', $id)->select('l.*', 'p.sku')->get();
                $actions[] = $this->action('credit', 'Kredito produktet', "/sales/$id/credits", [$this->field('reference', 'Numri i kreditimit'), $this->field('credited_on', 'Data', 'date'), $this->field('reason', 'Arsyeja', 'textarea'), $this->repeat('lines', 'Produktet', [$this->select('fulfillment_line_id', 'Rreshti i dërguar', $eligible->map(fn ($l) => ['value' => $l->id, 'label' => $l->sku.' / #'.$l->id])->all()), $this->field('quantity', 'Sasia', 'decimal')])]);
            }
        }
        if ($module === 'returns') {
            $lines = DB::table('return_lines')->where('sale_return_id', $id)->get();
            if (Gate::allows('inventory.manage')) {
                $actions[] = $this->action('receive', 'Prano mallrat fizikisht', "/returns/$id/receive", [$this->field('received_on', 'Data e pranimit', 'date'), $this->choice('condition', 'Gjendja', ['sellable' => 'Për shitje', 'quarantine' => 'Në inspektim', 'damaged' => 'Të dëmtuara']), $this->field('reason', 'Rezultati i inspektimit', 'textarea'), $this->repeat('lines', 'Sasitë e pranuara', [$this->select('return_line_id', 'Rreshti', $lines->map(fn ($l) => ['value' => $l->id, 'label' => '#'.$l->id])->all()), $this->field('quantity', 'Sasia', 'decimal')])]);
            } if (Gate::allows('sales.refund')) {
                $actions[] = $this->action('refund', 'Rimburso pagesën', "/returns/$id/refund", [$this->field('amount', 'Shuma EUR', 'decimal'), $this->select('account_id', 'Llogaria', $this->accounts(true)), $this->field('refunded_on', 'Data', 'date'), $this->field('reason', 'Arsyeja', 'textarea')]);
            }
        }
        if ($module==='bills') $related['Pagesat e faturës']=DB::table('payments')->where('bill_id',$id)->get();
        if ($module === 'bills' && Gate::allows('finance.manage')) {
            $actions[] = $this->action('pay', 'Paguaj faturën', "/bills/$id/pay", $this->paymentFields());
        }
        if ($module === 'bank' && Gate::allows('finance.reconcile')) {
            $actions[] = $this->action('match', 'Rakordo me ditarin', "/bank/$id/match", [$this->select('journal_id', 'Regjistrimi', DB::table('journals')->orderByDesc('id')->limit(200)->get()->map(fn ($j) => ['value' => $j->id, 'label' => $j->reference.' / '.$j->accounting_date])->all()), $this->field('reason', 'Arsyeja', 'textarea')]);
        }
        if ($module === 'deliveries' && Gate::allows('finance.manage')) {
            $actions[] = $this->action('collect', 'Regjistro arkëtimin nga korrieri', "/deliveries/$id/collect", [$this->field('amount', 'Shuma e arkëtuar EUR', 'decimal'), $this->field('accounting_date', 'Data e arkëtimit', 'date'), $this->field('reference', 'Referenca e korrierit')]);
        }
        if ($module === 'journals') {
            $lines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('journal_id', $id)->select('a.code', 'a.name', 'l.debit', 'l.credit', 'l.party_id', 'l.product_id', 'l.channel')->get();
            if ($record->source_type === 'manual' && Gate::allows('accounting.post')) {
                $actions[] = $this->action('reverse', 'Krijo regjistrim anulues', "/journals/$id/reverse", [$this->field('accounting_date', 'Data', 'date'), $this->field('reason', 'Arsyeja', 'textarea')]);
            }
        }
        if ($module === 'periods' && Gate::allows('accounting.close')) {
            $actions[] = $this->action('close', 'Mbyll periudhën', "/periods/$id/close", [$this->field('reason', 'Arsyeja', 'textarea')]);
        }
        if ($module === 'integrations') {
            unset($record->credentials);
            $related = ['Gabimet' => DB::table('integration_events')->where('connection_id', $id)->where('status', 'failed')->select('id', 'topic', 'attempts', 'last_error')->get(), 'Të dhënat e lexuara' => DB::table('shadow_records')->where('connection_id', $id)->select('id', 'external_type', 'external_id', 'source_modified_at')->limit(100)->get()];
            if (Gate::allows('integrations.manage')) {
                $actions[] = $this->action('sync', 'Lexo dhe rakordo WooCommerce', "/integrations/$id/reconcile", []);
            }
        }
        if ($module === 'fiscal') {
            unset($record->request_payload);
            $related = ['Përpjekjet' => DB::table('fiscal_attempts')->where('document_id', $id)->select('id', 'status', 'error', 'created_at')->get()];
        }
        if ($module === 'products' && Gate::allows('inventory.manage')) {
            $fields = [$this->field('name', 'Emri'), $this->select('category_id', 'Kategoria', DB::table('categories')->get()->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all()), $this->field('barcode', 'Barkodi'), $this->field('selling_price', 'Çmimi pa TVSH', 'decimal'), $this->field('minimum_stock', 'Stoku minimal', 'decimal'), $this->field('tax_rate', 'TVSH %', 'decimal'), $this->field('active', 'Aktiv', 'checkbox'), $this->field('notes', 'Shënime', 'textarea'), $this->field('reason', 'Arsyeja e ndryshimit', 'textarea')];
            foreach ($fields as &$field) {
                if (property_exists($record, $field['key'])) {
                    $field['default'] = $field['type'] === 'checkbox' ? (bool) $record->{$field['key']} : $record->{$field['key']};
                }
            } unset($field);
            $actions[] = $this->action('edit', 'Ndrysho katalogun', "/products/$id/edit", $fields);
            $actions[] = $this->action('condition', 'Transfero gjendjen fizike', "/products/$id/condition", [$this->field('quantity', 'Sasia', 'decimal'), $this->choice('from', 'Nga', ['sellable' => 'Për shitje', 'quarantine' => 'Në inspektim', 'damaged' => 'Të dëmtuara']), $this->choice('to', 'Në', ['sellable' => 'Për shitje', 'quarantine' => 'Në inspektim', 'damaged' => 'Të dëmtuara']), $this->field('accounting_date', 'Data', 'date'), $this->field('reason', 'Arsyeja', 'textarea')]);
        }
        if ($module === 'purchases' && Gate::allows('purchasing.manage')) {
            $actions[] = $this->action('supplier-return', 'Kthe mallra te furnitori', "/purchases/$id/supplier-return", [$this->field('reference', 'Referenca e kthimit'), $this->field('returned_on', 'Data', 'date'), $this->field('reason', 'Arsyeja / dokumenti i transportit', 'textarea'), $this->choice('condition', 'Gjendja', ['sellable' => 'Për shitje', 'quarantine' => 'Në inspektim', 'damaged' => 'Të dëmtuara']), $this->repeat('lines', 'Mallrat e kthyer', [$this->select('purchase_line_id', 'Rreshti i blerjes', collect($lines)->map(fn ($l) => ['value' => $l->id, 'label' => $l->sku.' · '.$l->name])->all()), $this->field('quantity', 'Sasia bazë', 'decimal')])]);
        }
        if ($module === 'counts') {
            $lines = json_decode($record->lines, true);
            unset($record->lines);
            if ($record->status === 'draft' && Gate::allows('inventory.adjust')) {
                $actions[] = $this->action('confirm', 'Konfirmo diferencat e numërimit', "/stock-counts/$id/confirm", []);
            }
        }
        if ($module === 'supplier-returns') {
            $lines = DB::table('supplier_return_lines')->where('supplier_return_id', $id)->get();
        }
        if ($module === 'drafts') {
            $lines = json_decode($record->lines, true);
            unset($record->lines);
            if ($record->status === 'draft' && Gate::allows('accounting.post')) {
                $fields = $this->fields('drafts');
                foreach ($fields as &$field) {
                    $field['default'] = $field['key'] === 'lines' ? $lines : ($record->{$field['key']} ?? '');
                } unset($field);
                $actions[] = $this->action('edit', 'Ndrysho draftin', "/drafts/$id/edit", $fields);
                $actions[] = $this->action('post', 'Posto draftin e balancuar', "/drafts/$id/post", []);
            }
        }
        if ($module === 'recurring' && Gate::allows('finance.manage')) {
            $related = ['Faturat e konfirmuara' => DB::table('recurring_runs')->where('recurring_expense_id', $id)->get()];
            $actions[] = $this->action('confirm', 'Konfirmo faturën e periudhës', "/recurring/$id/confirm", [$this->field('reference', 'Referenca e faturës reale'), $this->field('accounting_date', 'Data kontabël', 'date'), $this->field('vat_amount', 'TVSH sipas faturës', 'decimal', '0'), $this->field('recoverable_vat', 'TVSH e zbritshme sipas faturës', 'decimal', '0')]);
        }
        if ($module === 'integrations' && Gate::allows('integrations.manage')) {
            $events = DB::table('integration_events')->where('connection_id', $id)->whereIn('status', ['pending', 'failed'])->select('id', 'topic')->get();
            if ($events->isNotEmpty()) {
                $actions[] = $this->action('retry', 'Ripërpuno ngjarjen', "/integrations/$id/retry", [$this->select('event_id', 'Ngjarja', $events->map(fn ($e) => ['value' => $e->id, 'label' => '#'.$e->id.' '.$e->topic])->all()), $this->field('reason', 'Arsyeja', 'textarea')]);
            }
            $actions[] = $this->action('credentials', 'Përditëso kredencialet', "/integrations/$id/credentials", [$this->field('consumer_key', 'Çelësi vetëm për lexim', 'password'), $this->field('consumer_secret', 'Sekreti API', 'password'), $this->field('webhook_secret', 'Sekreti webhook', 'password'), $this->field('reason', 'Arsyeja', 'textarea')]);
        }
        if ($module === 'deliveries') {
            if (Gate::allows('sales.manage') && $record->status === 'in_transit') {
                $actions[] = $this->action('failed', 'Shëno dërgesën të dështuar', "/deliveries/$id/failed", [$this->field('reason', 'Arsyeja', 'textarea')]);
            }
            $f = DB::table('fulfillments')->where('id', $record->fulfillment_id)->first();
            if (Gate::allows('inventory.manage') && ! $f->recognized && in_array($record->status, ['failed', 'returning'], true)) {
                $goods = DB::table('fulfillment_lines')->where('fulfillment_id', $f->id)->get();
                $actions[] = $this->action('returned-parcel', 'Prano pakon e kthyer', "/deliveries/$id/returned-parcel", [$this->field('received_on', 'Data', 'date'), $this->field('reason', 'Arsyeja / inspektimi', 'textarea'), $this->repeat('lines', 'Mallrat', [$this->select('fulfillment_line_id', 'Rreshti', $goods->map(fn ($l) => ['value' => $l->id, 'label' => '#'.$l->id])->all()), $this->field('quantity', 'Sasia', 'decimal')])]);
            }
        }
        if (in_array($module, ['stock', 'returns'], true) && ! Gate::allows('finance.view') && ! Gate::allows('purchasing.view')) {
            unset($record->value,$record->balance_value);
            $lines = collect($lines)->map(function ($line) {
                if (is_object($line)) {
                    unset($line->cost_value,$line->ledger_cost);
                }

                return $line;
            });
        }
        if ($r->expectsJson()) {
            return response()->json(['record' => $record, 'lines' => $lines]);
        }
        $documentType = match ($module) {
            'purchases' => 'purchase','sales' => 'sale','bills' => 'bill','journals' => 'journal','products' => 'product',default => null
        };
        $attachments = $documentType ? DB::table('attachments')->where('document_type', $documentType)->where('document_id', $id)->select('id', 'original_name', 'created_at')->get() : [];

        return Inertia::render('business/Detail', ['module' => $module, 'title' => $title.' #'.$id, 'record' => $record, 'lines' => $lines, 'related' => $related, 'actions' => $actions, 'attachments' => $attachments, 'documentType' => $documentType]);
    }

    public function operation(Request $r, string $module, int $id, string $operation, Purchasing $purchasing, Selling $selling, Returns $returns, Finance $finance, Posting $posting)
    {
        $permissions = ['purchases.receive' => 'purchasing.manage', 'purchases.costs' => 'purchasing.manage', 'sales.dispatch' => 'sales.manage', 'sales.cancel' => 'sales.manage', 'sales.payments' => 'sales.manage', 'sales.credits' => 'sales.refund', 'fulfillments.deliver' => 'sales.manage', 'returns.receive' => 'inventory.manage', 'returns.refund' => 'sales.refund', 'bills.pay' => 'finance.manage', 'journals.reverse' => 'accounting.post', 'periods.close' => 'accounting.close', 'integrations.reconcile' => 'integrations.manage'];
        $key = $module.'.'.$operation;
        abort_unless(isset($permissions[$key]), 404);
        Gate::authorize($permissions[$key]);
        $d = 'required|string|regex:/^\d{1,12}(\.\d{1,6})?$/';
        $m = 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/';
        $rules = ['operation_key' => 'required|string|min:8|max:100'];
        $rules += match ($key) {
            'purchases.receive' => ['reference' => 'required|string|max:100|unique:purchase_receipts', 'received_on' => 'required|date', 'lines' => 'required|array|min:1', 'lines.*.purchase_line_id' => 'required|integer|distinct', 'lines.*.quantity' => $d],
            'purchases.costs' => $this->costRules(),
            'sales.dispatch' => ['dispatched_on' => 'required|date', 'lines' => 'required|array|min:1', 'lines.*.sale_line_id' => 'required|integer|distinct', 'lines.*.quantity' => $d, 'courier_id' => 'nullable|integer|exists:parties,id', 'tracking_reference' => 'nullable|string|max:100'],
            'sales.cancel','periods.close' => ['reason' => 'required|string|min:5|max:1000'],
            'sales.payments','bills.pay' => ['amount' => $m, 'account_id' => 'required|integer|exists:accounts,id', 'paid_on' => 'required|date', 'reference' => 'required|string|max:100', 'method' => 'required|string|max:40'],
            'sales.credits' => ['reference' => 'required|string|max:100|unique:sale_returns', 'credited_on' => 'required|date', 'reason' => 'required|string|min:5|max:1000', 'lines' => 'required|array|min:1', 'lines.*.fulfillment_line_id' => 'required|integer|distinct', 'lines.*.quantity' => $d],
            'fulfillments.deliver' => ['delivered_on' => 'required|date'],
            'returns.receive' => ['received_on' => 'required|date', 'reason' => 'required|string|min:5|max:1000', 'condition' => ['required', Rule::in(['sellable', 'quarantine', 'damaged'])], 'lines' => 'required|array|min:1', 'lines.*.return_line_id' => 'required|integer|distinct', 'lines.*.quantity' => $d],
            'returns.refund' => ['amount' => $m, 'account_id' => 'required|integer|exists:accounts,id', 'refunded_on' => 'required|date', 'reason' => 'required|string|min:5|max:1000'],
            'journals.reverse' => ['accounting_date' => 'required|date', 'reason' => 'required|string|min:5|max:1000'],default => [],
        };
        // Replay validation must not reject an already persisted reference before idempotency lookup.
        foreach (['reference'] as $ref) {
            if (isset($rules[$ref]) && $key !== 'purchases.costs') {
                $rules[$ref] = str_replace(['|unique:purchase_receipts', '|unique:sale_returns'], '', $rules[$ref]);
            }
        }
        $data = $r->validate($rules);
        $result = match ($key) {
            'purchases.receive' => $purchasing->receive($id, $data), 'purchases.costs' => ['id' => $purchasing->cost($id, array_diff_key($data, ['operation_key' => true]))],
            'sales.dispatch' => $selling->dispatch($id, $data),'sales.cancel' => $selling->cancel($id, $data),'sales.payments' => $selling->payment($id, $data),'sales.credits' => $returns->credit($id, $data),
            'fulfillments.deliver' => $selling->deliver($id, $data),'returns.receive' => $returns->receive($id, $data),'returns.refund' => $returns->refund($id, $data),'bills.pay' => $finance->payBill($id, $data),'journals.reverse' => $posting->reverse($id, $data),
            'periods.close' => $posting->once('period.close', $data + ['period_id' => $id], function () use ($id, $data) {
                $p = DB::table('accounting_periods')->where('id', $id)->lockForUpdate()->firstOrFail();
                if ($p->status !== 'open') {
                    throw new \DomainException('Periudha është mbyllur.');
                } DB::table('accounting_periods')->where('id', $id)->update(['status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => now(), 'updated_at' => now()]);
                Audit::record('period.closed', 'period', $id, ['reason' => $data['reason']]);

                return ['id' => $id];
            }),
            'integrations.reconcile' => app(WooSyncService::class)->reconcile($id),
        };
        if ($r->expectsJson()) {
            return response()->json($result);
        }

        return back()->with('success', 'Veprimi u regjistrua.');
    }

    public function quote(Request $r, Selling $selling)
    {
        Gate::authorize('sales.manage');
        $data = $r->validate($this->saleRules(false));

        return response()->json(['quote' => $selling->quote($data)]);
    }

    public function catalog(Request $r)
    {
        Gate::authorize('inventory.view');
        $q = DB::table('products')->where('active', true);
        if ($r->filled('barcode')) {
            $q->where('barcode', $r->input('barcode'));
        } else {
            $q->where(fn ($q) => $q->where('sku', 'like', '%'.$r->input('q', '').'%')->orWhere('name', 'like', '%'.$r->input('q', '').'%'));
        }

        return response()->json(['products' => $q->select('id', 'sku', 'name', 'barcode', 'selling_price')->limit(30)->get()]);
    }

    public function settings(Request $r)
    {
        Gate::authorize('settings.manage');
        $row = DB::table('settings')->where('key', 'accounting')->first();

        return Inertia::render('business/Settings', ['title' => 'Konfigurimi i biznesit', 'current' => $row ? json_decode($row->value, true) : null]);
    }

    public function saveSettings(Request $r)
    {
        Gate::authorize('settings.manage');
        $data = $r->validate(['vat_registered' => 'required|boolean', 'fiscal_required' => 'required|boolean', 'recognition_policy' => ['required', Rule::in(['dispatch', 'delivery'])], 'delivery_tax_rate' => 'required|string|regex:/^\d{1,2}(\.\d{1,4})?$/', 'reason' => 'required|string|min:10|max:1000']);
        if (app()->isProduction() && ! $data['fiscal_required'] && ! config('techpoint.fiscal_exemption_verified')) {
            throw new \DomainException('Përjashtimi fiskal kërkon bazë të verifikuar në konfigurimin e mbrojtur të prodhimit.');
        }
        DB::transaction(function () use ($data) {
            DB::table('settings')->updateOrInsert(['key' => 'accounting'], ['value' => json_encode(['configured' => true, ...$data], JSON_THROW_ON_ERROR), 'updated_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('settings.accounting_changed', 'settings', 'accounting', ['reason' => $data['reason']]);
        });

        return back()->with('success', 'Konfigurimi u ruajt për transaksionet e reja.');
    }

    public function reports(Request $r, Reports $reports)
    {
        Gate::authorize('reports.view');
        $filters = $this->filters($r);

        return Inertia::render('business/Reports', ['title' => 'Raportet', 'rows' => $reports->ledger($filters), 'metrics' => $reports->metrics($filters), 'filters' => $filters, 'channels' => DB::table('sales_channels')->select('code', 'name')->get(), 'products' => $this->products(), 'productsReport' => $reports->products($filters), 'budget' => $reports->budgets($filters), 'monthly' => $reports->monthly($filters)]);
    }

    public function export(Request $r, string $report, Reports $reports)
    {
        Gate::authorize('reports.export');
        $filters = $this->filters($r);
        $rows = $reports->export($report, $filters);
        Audit::record('report.exported', 'report', $report, ['filters' => $filters, 'rows' => count($rows)]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($rows) {
                fputcsv($out, array_keys($rows[0]), ',', '"', '');
            } foreach ($rows as $row) {
                $safe = array_map(function ($v) {
                    $s = (string) $v;

                    return preg_match('/^[=+@\-\t\r]/', $s) ? "'".$s : $s;
                }, $row);
                fputcsv($out, $safe, ',', '"', '');
            } fclose($out);
        }, $report.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function definition(string $module): array
    {
        abort_unless(isset(self::MODULES[$module]), 404);

        return self::MODULES[$module];
    }

    private function filters(Request $r): array
    {
        return $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'channel' => 'nullable|string|max:60', 'product_id' => 'nullable|integer|exists:products,id']);
    }

    private function field(string $key, string $label, string $type = 'text', mixed $default = ''): array
    {
        return compact('key', 'label', 'type', 'default');
    }

    private function select(string $key, string $label, array $options): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'select', 'options' => $options, 'default' => ''];
    }

    private function choice(string $key, string $label, array $options): array
    {
        return $this->select($key, $label, collect($options)->map(fn ($v, $k) => ['value' => $k, 'label' => $v])->values()->all());
    }

    private function repeat(string $key, string $label, array $fields): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'repeat', 'fields' => $fields];
    }

    private function action(string $key, string $label, string $url, array $fields): array
    {
        return compact('key', 'label', 'url', 'fields');
    }

    private function tools(string $module): array
    {
        if ($module === 'stock' && Gate::allows('inventory.adjust')) {
            return [$this->action('adjust', 'Korrigjim i dokumentuar i stokut', '/stock/adjust', [$this->select('product_id', 'Produkti', $this->products()), $this->field('quantity', 'Sasia me shenjë (+ shtim / − ulje)', 'decimal'), $this->field('value', 'Vlera e shtimit EUR (bosh për ulje)', 'decimal'), $this->choice('condition', 'Gjendja', ['sellable' => 'Për shitje', 'quarantine' => 'Në inspektim', 'damaged' => 'Të dëmtuara']), $this->field('accounting_date', 'Data', 'date'), $this->field('reason', 'Arsyeja (10+ shenja)', 'textarea')])];
        }
        if ($module === 'bank' && Gate::allows('finance.manage')) {
            return [$this->action('transfer', 'Transferim ndërmjet arkës dhe bankës', '/finance/transfer', [$this->select('from_account_id', 'Nga', $this->accounts(true)), $this->select('to_account_id', 'Në', $this->accounts(true)), $this->field('amount', 'Shuma EUR', 'decimal'), $this->field('accounting_date', 'Data', 'date'), $this->field('reason', 'Arsyeja', 'textarea')])];
        }

        return [];
    }

    private function accounts(bool $cash = false): array
    {
        $q = DB::table('accounts')->where('active', true);
        if ($cash) {
            $q->whereIn('code', ['1000', '1010']);
        }

        return $q->orderBy('code')->get()->map(fn ($a) => ['value' => $a->id, 'label' => $a->code.' · '.$a->name])->all();
    }

    private function parties(string $kind): array
    {
        return DB::table('parties')->where('kind', $kind)->where('active', true)->get()->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all();
    }

    private function products(): array
    {
        return DB::table('products')->where('active', true)->get()->map(fn ($p) => ['value' => $p->id, 'label' => $p->sku.' · '.$p->name])->all();
    }

    private function paymentFields(): array
    {
        return [$this->field('amount', 'Shuma EUR', 'decimal'), $this->select('account_id', 'Arka / Banka', $this->accounts(true)), $this->field('paid_on', 'Data e pagesës', 'date'), $this->field('reference', 'Referenca'), $this->choice('method', 'Mënyra', ['cash' => 'Para të gatshme', 'bank' => 'Bankë', 'card' => 'Kartelë', 'cod' => 'Korrier / COD'])];
    }

    private function costFields(): array
    {
        return [$this->choice('category', 'Kategoria', ['freight' => 'Transport ndërkombëtar', 'insurance' => 'Sigurim', 'customs' => 'Dogana', 'import_vat' => 'TVSH në import', 'clearance' => 'Zhdoganimi', 'domestic_transport' => 'Transport vendor i atribuueshëm', 'other' => 'Kosto tjetër']), $this->choice('allocation_basis', 'Baza e shpërndarjes', ['value' => 'Vlera', 'quantity' => 'Sasia', 'weight' => 'Pesha', 'volume' => 'Vëllimi']), $this->field('amount_eur', 'Shuma EUR', 'decimal'), $this->field('recoverable_percent', 'TVSH e rikuperueshme %', 'decimal', '0'), $this->field('document_reference', 'Referenca e dokumentit'), $this->field('capitalizable', 'Pjesë e kostos së inventarit', 'checkbox', true)];
    }

    private function costRules(): array
    {
        return ['category' => ['required', Rule::in(['freight', 'insurance', 'customs', 'import_vat', 'clearance', 'domestic_transport', 'other'])], 'allocation_basis' => ['required', Rule::in(['value', 'quantity', 'weight', 'volume'])], 'amount_eur' => 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/', 'recoverable_percent' => 'required|string|regex:/^\d{1,3}(\.\d{1,4})?$/', 'document_reference' => 'required|string|max:100', 'capitalizable' => 'required|boolean'];
    }

    private function saleRules(bool $header = true): array
    {
        $r = ['delivery_charge' => 'nullable|string|regex:/^\d{1,15}(\.\d{1,2})?$/', 'lines' => 'required|array|min:1', 'lines.*.product_id' => 'required|integer|exists:products,id|distinct', 'lines.*.quantity' => 'required|string|regex:/^\d{1,12}(\.\d{1,6})?$/', 'lines.*.unit_price' => 'nullable|string|regex:/^\d{1,15}(\.\d{1,8})?$/', 'lines.*.discount' => 'nullable|string|regex:/^\d{1,15}(\.\d{1,2})?$/'];
        if ($header) {
            $r += ['operation_key' => 'required|string|min:8|max:100', 'reference' => 'required|string|max:100', 'channel_id' => 'required|integer|exists:sales_channels,id', 'customer_id' => 'nullable|integer|exists:parties,id', 'ordered_on' => 'required|date', 'notes' => 'nullable|string|max:3000'];
        }

        return $r;
    }

    private function rules(string $m): array
    {
        $money = 'required|string|regex:/^\d{1,15}(\.\d{1,2})?$/';
        $decimal = 'required|string|regex:/^\d{1,12}(\.\d{1,6})?$/';

        return match ($m) {
            'products' => ['sku' => 'required|string|max:100|unique:products', 'name' => 'required|string|max:255', 'barcode' => 'nullable|string|max:100|unique:products', 'category_id' => 'nullable|integer|exists:categories,id', 'unit' => 'required|string|max:30', 'purchase_unit' => 'required|string|max:30', 'unit_conversion' => $decimal, 'purchase_currency' => 'required|string|size:3', 'purchase_price' => 'required|string|regex:/^\d{1,15}(\.\d{1,8})?$/', 'selling_price' => 'required|string|regex:/^\d{1,15}(\.\d{1,8})?$/', 'minimum_stock' => $decimal, 'tax_rate' => 'required|string|regex:/^\d{1,2}(\.\d{1,4})?$/', 'origin_country' => 'nullable|string|size:2', 'hs_code' => 'nullable|string|max:40', 'supplier_id' => 'nullable|integer|exists:parties,id', 'notes' => 'nullable|string|max:3000'],
            'categories' => ['name' => 'required|string|max:255', 'parent_id' => 'nullable|integer|exists:categories,id'],
            'suppliers','customers','couriers' => ['name' => 'required|string|max:255', 'country' => 'nullable|string|size:2', 'tax_number' => 'nullable|string|max:60', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:50', 'address' => 'nullable|string|max:1000'],
            'purchases' => ['reference' => 'required|string|max:100|unique:purchase_orders', 'supplier_id' => 'required|integer|exists:parties,id', 'invoice_reference' => 'nullable|string|max:100', 'ordered_on' => 'required|date', 'currency' => 'required|string|size:3', 'exchange_rate' => 'required|string|regex:/^\d{1,12}(\.\d{1,12})?$/', 'exchange_rate_source' => 'required|string|max:255', 'notes' => 'nullable|string|max:3000', 'lines' => 'required|array|min:1', 'lines.*.product_id' => 'required|integer|exists:products,id|distinct', 'lines.*.quantity' => $decimal, 'lines.*.unit_price' => 'required|string|regex:/^\d{1,15}(\.\d{1,8})?$/', 'lines.*.weight' => 'nullable|string|regex:/^\d{1,12}(\.\d{1,6})?$/', 'lines.*.volume' => 'nullable|string|regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'sales' => $this->saleRules(),
            'bills' => ['operation_key' => 'required|string|min:8|max:100', 'supplier_id' => 'nullable|integer|exists:parties,id', 'purchase_order_id' => 'nullable|integer|exists:purchase_orders,id', 'reference' => 'required|string|max:100', 'accounting_date' => 'required|date', 'account_id' => 'required|integer|exists:accounts,id', 'net_amount' => $money, 'vat_amount' => $money, 'recoverable_vat' => $money, 'category' => 'required|string|max:80', 'description' => 'required|string|max:1000'],
            'accounts' => ['code' => 'required|string|max:30|unique:accounts', 'name' => 'required|string|max:255', 'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])]],
            'periods' => ['name' => 'required|string|max:100', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on'],
            'journals' => ['operation_key' => 'required|string|min:8|max:100', 'accounting_date' => 'required|date', 'description' => 'required|string|min:5|max:1000', 'lines' => 'required|array|min:2', 'lines.*.account_id' => 'required|integer|exists:accounts,id', 'lines.*.debit' => $money, 'lines.*.credit' => $money],
            'channels' => ['code' => 'required|string|max:60|unique:sales_channels', 'name' => 'required|string|max:255'],
            'integrations' => ['name' => 'required|string|max:255', 'base_url' => 'required|url:https|max:1024', 'consumer_key' => 'required|string|max:255', 'consumer_secret' => 'required|string|max:255', 'webhook_secret' => 'required|string|min:20|max:255'],
            'users' => ['name' => 'required|string|max:255', 'email' => 'required|email|unique:users', 'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()], 'role_id' => 'required|integer|exists:roles,id'],
            'roles' => ['name' => 'required|string|max:60|unique:roles', 'label' => 'required|string|max:255'],
            'budgets' => ['account_id' => 'required|integer|exists:accounts,id', 'month' => 'required|date', 'amount' => $money],
            'recurring' => ['name' => 'required|string|max:255', 'supplier_id' => 'nullable|integer|exists:parties,id', 'account_id' => 'required|integer|exists:accounts,id', 'amount' => $money, 'next_on' => 'required|date'],
            'bank' => ['account_id' => 'required|integer|exists:accounts,id', 'external_reference' => 'required|string|max:100', 'date' => 'required|date', 'amount' => 'required|string|regex:/^-?\d{1,15}(\.\d{1,2})?$/', 'description' => 'required|string|max:1000'],
            default => [],
        };
    }

    private function fields(string $m): array
    {
        return match ($m) {
            'settlements' => [$this->select('courier_id', 'Korrieri', $this->parties('courier')), $this->field('reference', 'Referenca e raportit'), $this->field('settled_on', 'Data', 'date'), $this->select('account_id', 'Arka / Banka', $this->accounts(true)), $this->field('fees', 'Tarifat EUR', 'decimal', '0'), $this->field('remitted', 'Shuma e transferuar EUR', 'decimal'), $this->repeat('lines', 'Dërgesat e rakorduara', [$this->select('delivery_id', 'Dërgesa', DB::table('deliveries')->get()->map(fn ($d) => ['value' => $d->id, 'label' => '#'.$d->id.' / '.($d->tracking_reference ?? '')])->all()), $this->field('amount', 'Arkëtimi sipas raportit EUR', 'decimal')])],
            'products' => [$this->field('sku', 'SKU'), $this->field('name', 'Emri i produktit'), $this->field('barcode', 'Barkodi'), $this->select('category_id', 'Kategoria', DB::table('categories')->get()->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all()), $this->field('unit', 'Njësia e shitjes', 'text', 'copë'), $this->field('purchase_unit', 'Njësia e blerjes', 'text', 'copë'), $this->field('unit_conversion', 'Konvertimi në njësi bazë', 'decimal', '1'), $this->field('purchase_currency', 'Monedha e blerjes', 'text', 'EUR'), $this->field('purchase_price', 'Çmimi referencë i blerjes', 'decimal', '0'), $this->field('selling_price', 'Çmimi i shitjes pa TVSH', 'decimal', '0'), $this->field('tax_rate', 'Norma e TVSH %', 'decimal', '0'), $this->field('minimum_stock', 'Stoku minimal', 'decimal', '0'), $this->field('origin_country', 'Origjina (kodi me dy shkronja)'), $this->field('hs_code', 'Kodi doganor / HS'), $this->select('supplier_id', 'Furnitori', $this->parties('supplier')), $this->field('notes', 'Shënime', 'textarea')],
            'categories' => [$this->field('name', 'Emri'), $this->select('parent_id', 'Kategoria prind', DB::table('categories')->get()->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all())],
            'counts' => [$this->field('reference', 'Numri i numërimit'), $this->field('counted_on', 'Data', 'date'), $this->field('reason', 'Arsyeja', 'textarea'), $this->repeat('lines', 'Numërimi fizik', [$this->select('product_id', 'Produkti', $this->products()), $this->field('quantity', 'Sasia e numëruar', 'decimal')])],
            'drafts' => [$this->field('reference', 'Referenca'), $this->field('accounting_date', 'Data kontabël', 'date'), $this->field('description', 'Përshkrimi', 'textarea'), $this->repeat('lines', 'Rreshtat e ditarit', [$this->select('account_id', 'Llogaria', $this->accounts()), $this->field('debit', 'Debi EUR', 'decimal', '0'), $this->field('credit', 'Kredi EUR', 'decimal', '0')])],
            'suppliers','customers','couriers' => [$this->field('name', 'Emri'), $this->field('country', 'Shteti (kodi me dy shkronja)'), $this->field('tax_number', 'Numri fiskal'), $this->field('email', 'Email', 'email'), $this->field('phone', 'Telefoni'), $this->field('address', 'Adresa', 'textarea')],
            'purchases' => [$this->field('reference', 'Numri i porosisë'), $this->select('supplier_id', 'Furnitori', $this->parties('supplier')), $this->field('invoice_reference', 'Numri i faturës'), $this->field('ordered_on', 'Data', 'date'), $this->field('currency', 'Monedha', 'text', 'EUR'), $this->field('exchange_rate', 'Kursi për 1 njësi në EUR', 'decimal', '1'), $this->field('exchange_rate_source', 'Burimi i kursit'), $this->repeat('lines', 'Produktet', [$this->select('product_id', 'Produkti', $this->products()), $this->field('quantity', 'Sasia', 'decimal'), $this->field('unit_price', 'Çmimi për njësi', 'decimal'), $this->field('weight', 'Pesha për njësi', 'decimal', '0'), $this->field('volume', 'Vëllimi për njësi', 'decimal', '0')])],
            'sales' => [$this->field('reference', 'Numri i porosisë'), $this->select('channel_id', 'Kanali', DB::table('sales_channels')->where('active', true)->get()->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all()), $this->select('customer_id', 'Klienti', $this->parties('customer')), $this->field('ordered_on', 'Data', 'date'), $this->field('delivery_charge', 'Tarifa e klientit për dërgesë pa TVSH', 'decimal', '0'), $this->repeat('lines', 'Produktet', [$this->select('product_id', 'Produkti', $this->products()), $this->field('quantity', 'Sasia', 'decimal', '1'), $this->field('unit_price', 'Çmimi pa TVSH (bosh = katalogu)', 'decimal'), $this->field('discount', 'Zbritja EUR', 'decimal', '0')])],
            'bills' => [$this->select('supplier_id', 'Furnitori', $this->parties('supplier')), $this->field('reference', 'Numri i faturës'), $this->field('accounting_date', 'Data kontabël', 'date'), $this->select('account_id', 'Llogaria (2150 për blerje inventari)', $this->accounts()), $this->field('net_amount', 'Shuma pa TVSH', 'decimal'), $this->field('vat_amount', 'TVSH e faturës', 'decimal', '0'), $this->field('recoverable_vat', 'TVSH e zbritshme', 'decimal', '0'), $this->field('category', 'Kategoria'), $this->field('description', 'Përshkrimi', 'textarea')],
            'accounts' => [$this->field('code', 'Kodi'), $this->field('name', 'Emri'), $this->choice('type', 'Lloji', ['asset' => 'Aktiv', 'liability' => 'Detyrim', 'equity' => 'Kapital', 'revenue' => 'Të ardhura', 'expense' => 'Shpenzim'])],
            'periods' => [$this->field('name', 'Emri'), $this->field('starts_on', 'Nga', 'date'), $this->field('ends_on', 'Deri', 'date')],
            'journals' => [$this->field('accounting_date', 'Data kontabël', 'date'), $this->field('description', 'Përshkrimi', 'textarea'), $this->repeat('lines', 'Rreshtat e ditarit', [$this->select('account_id', 'Llogaria', $this->accounts()), $this->field('debit', 'Debi EUR', 'decimal', '0'), $this->field('credit', 'Kredi EUR', 'decimal', '0')])],
            'channels' => [$this->field('code', 'Kodi'), $this->field('name', 'Emri')],
            'integrations' => [$this->field('name', 'Emri'), $this->field('base_url', 'Adresa HTTPS e WooCommerce'), $this->field('consumer_key', 'Çelësi me leje vetëm për lexim', 'password'), $this->field('consumer_secret', 'Sekreti i API', 'password'), $this->field('webhook_secret', 'Sekreti i webhook', 'password')],
            'users' => [$this->field('name', 'Emri'), $this->field('email', 'Email', 'email'), $this->field('password', 'Fjalëkalimi (12+ shenja)', 'password'), $this->select('role_id', 'Roli', DB::table('roles')->get()->map(fn ($a) => ['value' => $a->id, 'label' => $a->label])->all())],
            'roles' => [$this->field('name', 'Kodi i rolit'), $this->field('label', 'Emri i rolit')],
            'budgets' => [$this->select('account_id', 'Llogaria', $this->accounts()), $this->field('month', 'Muaji (dita e parë)', 'date'), $this->field('amount', 'Buxheti EUR', 'decimal')],
            'recurring' => [$this->field('name', 'Përshkrimi'), $this->select('supplier_id', 'Furnitori', $this->parties('supplier')), $this->select('account_id', 'Llogaria', $this->accounts()), $this->field('amount', 'Shuma pa TVSH', 'decimal'), $this->field('next_on', 'Data e ardhshme', 'date')],
            'bank' => [$this->select('account_id', 'Llogaria', $this->accounts(true)), $this->field('external_reference', 'Referenca e bankës'), $this->field('date', 'Data', 'date'), $this->field('amount', 'Shuma me shenjë EUR', 'decimal'), $this->field('description', 'Përshkrimi', 'textarea')],default => [],
        };
    }

    private function labels(): array
    {
        return ['id' => '#', 'sku' => 'SKU', 'name' => 'Emri', 'reference' => 'Referenca', 'quantity' => 'Sasia', 'on_hand' => 'Në stok', 'reserved' => 'Rezervuar', 'value' => 'Vlera EUR', 'selling_price' => 'Çmimi EUR', 'purchase_price' => 'Çmimi i blerjes', 'status' => 'Gjendja', 'order_status' => 'Porosia', 'fulfillment_status' => 'Përmbushja', 'payment_status' => 'Pagesa', 'delivery_status' => 'Dërgesa', 'gross_total' => 'Gjithsej EUR', 'net_total' => 'Pa TVSH EUR', 'tax_total' => 'TVSH EUR', 'created_at' => 'Regjistruar', 'accounting_date' => 'Data kontabël', 'debit_total' => 'Debi EUR', 'credit_total' => 'Kredi EUR', 'email' => 'Email', 'active' => 'Aktiv', 'condition' => 'Gjendja fizike'];
    }

    private function permissionLabel(string $name): string
    {
        [$area,$action] = explode('.', $name);

        return (['dashboard' => 'Përmbledhja', 'inventory' => 'Inventari', 'purchasing' => 'Blerjet', 'sales' => 'Shitjet', 'finance' => 'Financat', 'accounting' => 'Kontabiliteti', 'reports' => 'Raportet', 'integrations' => 'Integrimet', 'users' => 'Përdoruesit', 'settings' => 'Konfigurimi', 'audit' => 'Auditimi'][$area] ?? $area).' · '.(['view' => 'shiko', 'manage' => 'menaxho', 'adjust' => 'korrigjo stokun', 'discount' => 'apliko zbritje', 'refund' => 'rimburso', 'post' => 'posto regjistrime', 'close' => 'mbyll periudhat', 'export' => 'eksporto', 'reconcile' => 'rakordo'][$action] ?? $action);
    }
}
