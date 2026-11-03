<?php

namespace App\Services\Reports\Leads;

use App\Services\Salesforce\InterestGenerationStore;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Admin-only aggregate preview; it never enters the certified reader or cache. */
final class InterestProvisionalPreviewService
{
    public function __construct(private readonly InterestGenerationStore $store) {}

    /** @return array<string, mixed> */
    public function septemberOctober2026(): array
    {
        if (config('salesforce.interest_provisional_preview_enabled') !== true) {
            throw new RuntimeException('The provisional Interest preview is disabled.');
        }
        $generationId = (int) config('salesforce.interest_provisional_preview_generation_id', 1);
        $generation = $this->store->generation($generationId);
        if ($generation->status !== 'validating' || ! $generation->f2_complete || ! $generation->f5_complete
            || $generation->published_at !== null || $generation->base_generation_id !== null) {
            throw new RuntimeException('Configured provisional seed is not an intact unverified initial generation.');
        }
        $rows = [];
        foreach (['2026-09', '2026-10'] as $month) {
            $start = CarbonImmutable::parse($month.'-01', 'Europe/Madrid')->startOfDay()->utc();
            $end = $start->setTimezone('Europe/Madrid')->addMonth()->startOfDay()->utc();
            $aggregate = $this->store->rows($generationId)->where('version.functional_created_at', '>=', $start)
                ->where('version.functional_created_at', '<', $end)
                ->selectRaw('COUNT(*) as rows')
                ->selectRaw('SUM(CASE WHEN version.is_deleted THEN 1 ELSE 0 END) as deleted')->first();
            $rows[] = ['period' => $month, 'rows' => (int) $aggregate->rows, 'deleted' => (int) $aggregate->deleted];
        }

        return [
            'generation_id' => $generationId,
            'cutoff_at' => (string) $generation->cutoff_at,
            'certified' => false,
            'periods' => $rows,
        ];
    }
}
