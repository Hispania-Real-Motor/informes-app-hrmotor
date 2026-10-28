<?php

namespace App\Services\Reports\Leads;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class InterestDatasetUnavailable extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'interest_dataset_unavailable',
            'message' => 'El informe de intereses no está disponible porque sus datos todavía no están certificados.',
        ], 503, ['Cache-Control' => 'no-store']);
    }
}
