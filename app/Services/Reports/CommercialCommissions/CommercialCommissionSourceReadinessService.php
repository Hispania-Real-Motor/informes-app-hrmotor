<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\ReportSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CommercialCommissionSourceReadinessService
{
    /** @return array{ready: bool, components: array<string, array<string, mixed>>, blocking: array<int, string>, warnings: array<int, string>, source_state: array<string, mixed>} */
    public function inspect(string $scope, string $month, array $dashboard): array
    {
        $period = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $components = match ($scope) {
            'commercials' => [
                'sales' => $this->syncRunState('salesforce_opportunities', 'Oportunidades', $period),
                'purchases' => $this->syncRunState('salesforce_opportunities', 'Compras producidas', $period),
                'cancellations' => $this->localTableState('commercial_financing_penalties', 'Penalizaciones financieras'),
                'reviews' => $this->syncRunState('commercial_reviews', 'Reseñas comerciales', $period),
                'adjustments' => $this->localTableState('commercial_commission_adjustments', 'Ajustes auditados'),
            ],
            'delegations' => [
                'sales' => $this->syncRunState('salesforce_opportunities', 'Oportunidades', $period),
                'reviews' => $this->delegationReviewsState($dashboard, $period),
            ],
            'area_manager' => [
                'sales' => $this->syncRunState('salesforce_opportunities', 'Oportunidades', $period),
                'objectives' => $this->localTableState('commercial_commission_month_settings', 'Objetivos mensuales', $month),
            ],
            'financials' => [
                'sales' => $this->syncRunState('salesforce_opportunities', 'Oportunidades', $period),
                'incentives' => $this->localTableState('commercial_commission_month_settings', 'Tramos de incentivos', $month),
            ],
            'call_center' => [
                'sales' => $this->syncRunState('salesforce_opportunities', 'Oportunidades', $period),
                'tasaciones' => $this->syncRunState('salesforce_tasaciones', 'Tasaciones', $period),
            ],
            'contact_center' => [
                'leads' => $this->syncRunState('leads_dashboard', 'Leads y citas', $period),
                'sales' => $this->syncRunState('salesforce_opportunities', 'Oportunidades', $period),
            ],
        };

        $dashboardIssues = collect($dashboard['issues'] ?? [])->map(fn ($issue): string => trim((string) $issue))->filter()->values();
        $blocking = collect($components)->where('blocking', true)->pluck('message')->filter()->merge($dashboardIssues)->unique()->values()->all();
        $warnings = collect($dashboard['warnings'] ?? [])->map(fn ($warning): string => trim((string) $warning))->filter();

        if ($scope === 'delegations') {
            $delegationRows = collect($dashboard['delegation_rows'] ?? []);
            $managerAlerts = $delegationRows->pluck('store_manager_alert')->filter();
            $validManagerAlerts = $delegationRows
                ->filter(fn (array $row): bool => (int) ($row['store_manager_distinct_count'] ?? 0) > 2)
                ->pluck('store_manager_alert')
                ->filter();

            // Legacy snapshots may retain obsolete coverage warnings. Remove only
            // alerts explicitly linked to manager rows, preserving source warnings.
            $warnings = $warnings
                ->reject(fn (string $warning): bool => $managerAlerts->contains($warning))
                ->merge($validManagerAlerts);
        }

        return [
            'ready' => $blocking === [],
            'components' => $components,
            'blocking' => $blocking,
            'warnings' => $warnings->unique()->values()->all(),
            'source_state' => collect($components)->map(fn (array $state): array => [
                'status' => $state['status'],
                'checked_at' => $state['checked_at'],
                'updated_at' => $state['updated_at'],
                'message' => $state['message'],
            ])->all(),
        ];
    }

    private function syncRunState(string $dataset, string $label, CarbonImmutable $month): array
    {
        if (! Schema::hasTable('report_sync_runs')) {
            return $this->state($label, 'error', true, null, 'No existe metadata de sincronización.');
        }

        return $month->isCurrentMonth()
            ? $this->currentMonthSyncRunState($dataset, $label, $month)
            : $this->completedFullMonthState($dataset, $label, $month, true);
    }

    /**
     * Returns verifiable complete historical coverage without applying the
     * freshness policy. Historical reference months must not become invalid
     * merely because their successful synchronization is old.
     */
    public function historicalFullMonthCoverageState(string $dataset, string $label, CarbonImmutable $month): array
    {
        if (! Schema::hasTable('report_sync_runs')) {
            return $this->state($label, 'error', true, null, 'No existe metadata de sincronización.');
        }

        return $this->completedFullMonthState($dataset, $label, $month, false);
    }

    private function completedFullMonthState(string $dataset, string $label, CarbonImmutable $month, bool $requireFresh): array
    {
        $monthEnd = $month->addMonth();
        $run = ReportSyncRun::query()
            ->where('dataset', $dataset)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('period_start_at', '<=', $month)
            ->where('period_end_at', '>=', $monthEnd)
            ->latest('started_at')
            ->first();

        if ($run === null) {
            return $this->state($label, 'error', true, null, 'No existe una sincronización verificable que cubra el mes.');
        }

        $maxAge = max(1, (int) config('commercial_commissions.closure_source_max_age_hours', 48));
        if ($requireFresh && $run->completed_at->lt(now()->subHours($maxAge))) {
            return $this->state($label, 'stale', true, $run->completed_at->toIso8601String(), 'La sincronización está desactualizada.');
        }

        return $this->state($label, 'ready', false, $run->completed_at->toIso8601String(), 'Fuente sincronizada correctamente.');
    }

    private function currentMonthSyncRunState(string $dataset, string $label, CarbonImmutable $month): array
    {
        $cutoff = CarbonImmutable::now($month->getTimezone())->min($month->addMonth());
        $runs = ReportSyncRun::query()
            ->where('dataset', $dataset)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('period_end_at', '>', $month)
            ->where('period_start_at', '<', $cutoff)
            ->orderBy('period_start_at')
            ->orderBy('period_end_at')
            ->get();

        if ($runs->isEmpty()) {
            return $this->state($label, 'error', true, null, 'No existe una sincronización completada que cubra el mes provisional.');
        }

        $coveredUntil = $month;
        $coverageCompletedAt = null;
        foreach ($runs as $run) {
            $start = CarbonImmutable::parse($run->period_start_at);
            $end = CarbonImmutable::parse($run->period_end_at);

            if ($start->greaterThan($coveredUntil)) {
                return $this->state($label, 'error', true, $run->completed_at->toIso8601String(), 'Existe un hueco real entre sincronizaciones del mes provisional.');
            }

            if ($end->greaterThan($coveredUntil)) {
                $coveredUntil = $end;
                $coverageCompletedAt = $run->completed_at;
            }

            if ($coveredUntil->greaterThanOrEqualTo($cutoff)) {
                break;
            }
        }

        if ($coveredUntil->lessThan($cutoff)) {
            $latest = $runs->sortByDesc(fn (ReportSyncRun $run): int => $run->completed_at->getTimestamp())->first();

            return $this->state($label, 'error', true, $latest?->completed_at?->toIso8601String(), 'La cobertura del mes provisional no alcanza un corte actual.');
        }

        $updatedAt = $coverageCompletedAt;
        $maxAge = max(1, (int) config('commercial_commissions.closure_source_max_age_hours', 48));
        if ($updatedAt === null || $updatedAt->lt(now()->subHours($maxAge))) {
            return $this->state($label, 'stale', true, $updatedAt?->toIso8601String(), 'La sincronización provisional está desactualizada.');
        }

        return $this->state($label, 'ready', false, $updatedAt->toIso8601String(), 'Cobertura continua y reciente del mes provisional verificada.');
    }

    private function localTableState(string $table, string $label, ?string $month = null): array
    {
        if (! Schema::hasTable($table)) {
            return $this->state($label, 'error', true, null, 'Falta el esquema local requerido.');
        }

        $query = DB::table($table);
        if ($month !== null && Schema::hasColumn($table, 'month')) {
            $query->where('month', $month);
        }
        $updated = Schema::hasColumn($table, 'updated_at') ? $query->max('updated_at') : null;

        return $this->state($label, 'ready', false, $updated ? CarbonImmutable::parse($updated)->toIso8601String() : null, 'Fuente local disponible; cero registros es un resultado válido.');
    }

    private function delegationReviewsState(array $dashboard, CarbonImmutable $month): array
    {
        $rows = collect($dashboard['delegation_rows'] ?? []);
        if ($rows->isEmpty()) {
            return $this->state('Reseñas de Delegaciones', 'ready', false, null, 'No hay delegaciones comisionables; cero consultas es válido.');
        }

        $rowsRequiringEndpoint = $rows->reject(
            fn (array $row): bool => $this->isEconomicallyInertNotApplicableReviewRow($row)
        );

        if ($rowsRequiringEndpoint->isEmpty()) {
            return $this->state('Reseñas de Delegaciones', 'ready', false, null, 'No hay delegaciones que requieran consulta de reseñas; cero consultas es válido.');
        }

        $invalid = $rowsRequiringEndpoint->filter(fn (array $row): bool => ($row['reviews_technical_status'] ?? null) !== 'available');
        $oldest = $rowsRequiringEndpoint->pluck('reviews_fetched_at')->filter()->map(fn ($value) => CarbonImmutable::parse($value))->sort()->first();
        $maxAgeMinutes = max(1, (int) config('services.internal_reviews.cache_minutes', 15)) + 5;

        if ($invalid->isNotEmpty()) {
            return $this->state('Reseñas de Delegaciones', 'error', true, $oldest?->toIso8601String(), 'El endpoint interno no devolvió datos válidos para todas las delegaciones.');
        }
        if ($oldest === null || $oldest->lt(now()->subMinutes($maxAgeMinutes))) {
            return $this->state('Reseñas de Delegaciones', 'stale', true, $oldest?->toIso8601String(), 'La caché del endpoint interno no tiene una verificación reciente.');
        }

        return $this->state('Reseñas de Delegaciones', 'ready', false, $oldest->toIso8601String(), 'Endpoint interno y caché verificados para '.$month->translatedFormat('F \d\e Y').'.');
    }

    private function isEconomicallyInertNotApplicableReviewRow(array $row): bool
    {
        if (($row['reviews_technical_status'] ?? null) !== 'not_applicable') {
            return false;
        }

        $numericFields = [
            'target_deliveries',
            'deliveries_count',
            'rentability_total',
            'prima_final_before_reviews',
            'reviews_commission_amount',
            'total_commission',
        ];

        foreach ([...$numericFields, 'objective_reached'] as $field) {
            if (! array_key_exists($field, $row)) {
                return false;
            }
        }

        foreach ($numericFields as $field) {
            if (! is_numeric($row[$field])) {
                return false;
            }
        }

        return (float) $row['target_deliveries'] <= 0.0
            && (float) $row['deliveries_count'] <= 0.0
            && $row['objective_reached'] === false
            && (float) $row['rentability_total'] === 0.0
            && (float) $row['prima_final_before_reviews'] === 0.0
            && (float) $row['reviews_commission_amount'] === 0.0
            && (float) $row['total_commission'] === 0.0;
    }

    private function state(string $label, string $status, bool $blocking, ?string $updatedAt, string $message): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'blocking' => $blocking,
            'updated_at' => $updatedAt,
            'checked_at' => now()->toIso8601String(),
            'message' => $message,
        ];
    }
}
