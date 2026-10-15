<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class V1Controller extends Controller
{
    public function products()
    {
        return response()->json(['data' => DB::table('products as p')->leftJoin('stock_balances as b', fn ($join) => $join->on('b.product_id', '=', 'p.id')->where('b.condition', 'sellable'))->where('p.active', true)->select('p.id', 'p.sku', 'p.name', 'p.barcode', 'p.selling_price', DB::raw('COALESCE(b.on_hand,0) as on_hand'), DB::raw('COALESCE(b.reserved,0) as reserved'))->orderBy('p.sku')->get()]);
    }

    public function sales()
    {
        return response()->json(['data' => DB::table('sales')->select('id', 'reference', 'ordered_on', 'order_status', 'fulfillment_status', 'payment_status', 'delivery_status', 'net_total', 'tax_total', 'gross_total', 'promotion_code', 'promotion_discount')->orderByDesc('id')->limit(200)->get()]);
    }

    public function report(Request $request, string $report, Reports $reports)
    {
        $filters = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'channel' => 'nullable|string|max:60', 'product_id' => 'nullable|integer|exists:products,id']);

        return response()->json(['report' => $report, 'data' => $reports->export($report, $filters)]);
    }
}
