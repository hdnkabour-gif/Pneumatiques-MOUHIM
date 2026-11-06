<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\Sales\SaleDocuments;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SaleDocumentController
{
    public function show(Sale $sale, SaleDocuments $documents): Response
    {
        abort_unless(auth()->user()->is_active, 403);
        Gate::authorize('view', $sale);
        abort_if($sale->isDraft(), 404);

        return $this->inline($sale, $documents);
    }

    /** Signed link sent to the customer by WhatsApp. */
    public function public(Sale $sale, SaleDocuments $documents): Response
    {
        abort_if($sale->isDraft(), 404);

        return $this->inline($sale, $documents);
    }

    private function inline(Sale $sale, SaleDocuments $documents): Response
    {
        return response($documents->pdf($sale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$sale->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
