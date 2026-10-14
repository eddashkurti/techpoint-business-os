<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\ProductLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class DocumentController extends Controller
{
    public function label(int $product, ProductLabel $labels)
    {
        Gate::authorize('inventory.view');
        $record = DB::table('products')->where('id', $product)->firstOrFail();
        $code = $record->barcode ?: $record->sku;
        $svg = $labels->svg($code);
        Audit::record('product_label.print_view', 'product', $product);

        return response()->view('product-label', ['product' => $record, 'code' => $code, 'svg' => $svg])->header('Cache-Control', 'private, no-store');
    }

    private function authorizeDocument(string $type, int $id): void
    {
        $map = ['purchase' => ['purchase_orders', 'purchasing.view'], 'bill' => ['bills', 'finance.view'], 'sale' => ['sales', 'sales.view'], 'journal' => ['journals', 'accounting.view'], 'product' => ['products', 'inventory.view']];
        abort_unless(isset($map[$type]), 404);
        Gate::authorize($map[$type][1]);
        abort_unless(DB::table($map[$type][0])->where('id', $id)->exists(), 404);
    }

    public function upload(Request $r)
    {
        $d = $r->validate(['document_type' => 'required|string', 'document_id' => 'required|integer', 'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240']);
        $this->authorizeDocument($d['document_type'], $d['document_id']);
        Gate::authorize(match ($d['document_type']) {
            'purchase' => 'purchasing.manage','bill' => 'finance.manage','sale' => 'sales.manage','journal' => 'accounting.post','product' => 'inventory.manage'
        });
        $f = $r->file('file');
        $path = $f->store('documents', 'local');
        try {
            $id = DB::transaction(function () use ($d, $f, $path) {
                $id = DB::table('attachments')->insertGetId(['document_type' => $d['document_type'], 'document_id' => $d['document_id'], 'original_name' => $f->getClientOriginalName(), 'path' => $path, 'mime' => $f->getMimeType(), 'size' => $f->getSize(), 'sha256' => hash_file('sha256', $f->getRealPath()), 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
                Audit::record('document.attached', 'attachment', $id);

                return $id;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return $r->expectsJson() ? response()->json(['id' => $id], 201) : back()->with('success', 'Dokumenti u ruajt privatisht.');
    }

    public function download(int $id)
    {
        $a = DB::table('attachments')->where('id', $id)->firstOrFail();
        $this->authorizeDocument($a->document_type, $a->document_id);
        Audit::record('document.downloaded', 'attachment', $id);

        return Storage::disk('local')->download($a->path, $a->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function receipt(int $sale)
    {
        Gate::authorize('sales.view');
        $record = DB::table('sales')->where('id', $sale)->firstOrFail();
        $lines = DB::table('sale_lines')->where('sale_id', $sale)->get();
        Audit::record('internal_receipt.print_view', 'sale', $sale);

        return response()->view('internal-receipt', compact('record', 'lines'))->header('Cache-Control', 'private, no-store');
    }
}
