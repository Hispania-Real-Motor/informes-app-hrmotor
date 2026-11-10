<?php

namespace App\Services\Reports\CommercialCommissions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

class CommercialCommissionAuditProjectionService
{
    public function __construct(private readonly CommercialCommissionClosureService $closures) {}

    public function build(?string $month, ?string $selectedScope = null): array
    {
        $requestedMonth = $this->resolveAuditMonth($month);
        $statuses = $this->closures->statuses($requestedMonth->format('Y-m'));
        $monthKey = (string) (data_get($statuses, 'commercials.month') ?? data_get(collect($statuses)->first(), 'month'));
        $scope = array_key_exists((string) $selectedScope, $statuses) ? (string) $selectedScope : (string) array_key_first($statuses);
        $result = [
            'scope' => $scope,
            'status' => $this->restrictedStatus($statuses[$scope]),
            'rows' => [],
            'alerts' => [],
            'available' => true,
            'warning' => null,
        ];

        try {
            $snapshot = $this->closures->candidateOrDefinitiveSnapshot($monthKey, $scope);
            $dashboard = $snapshot !== null
                ? $this->snapshotDashboard($snapshot, $scope)
                : $this->closures->buildLiveDashboard($monthKey, $scope, false);
            $result['rows'] = $this->finalRows($dashboard, $scope);
            $result['alerts'] = collect($result['rows'])->pluck('alert')->filter()->unique()->values()->all();
        } catch (Throwable $exception) {
            Log::error('Bloque de auditoria de comisiones no disponible.', [
                'integration' => 'commission_audit_projection',
                'scope' => $scope,
                'exception_type' => $exception::class,
            ]);
            $result['available'] = false;
            $result['warning'] = 'Bloque no disponible temporalmente. La incidencia ha quedado registrada.';
        }

        return [
            'month' => $monthKey,
            'month_label' => CarbonImmutable::createFromFormat('!Y-m', $monthKey)?->locale('es')->translatedFormat('F \d\e Y') ?? $monthKey,
            'available_months' => $this->availableMonths(),
            'selected_scope' => $scope,
            'scope_statuses' => collect($statuses)->map(fn (array $status): array => $this->restrictedStatus($status))->all(),
            'scope' => $result,
        ];
    }

    /** @return array<int, array{value: string, label: string}> */
    private function availableMonths(): array
    {
        $firstMonth = CarbonImmutable::parse('2026-07-01', config('app.timezone'))->startOfMonth();
        $currentMonth = CarbonImmutable::now(config('app.timezone'))->startOfMonth();
        $months = [];

        for ($month = $currentMonth; $month->greaterThanOrEqualTo($firstMonth); $month = $month->subMonth()) {
            $months[] = [
                'value' => $month->format('Y-m'),
                'label' => $month->locale('es')->translatedFormat('F \d\e Y'),
            ];
        }

        return $months;
    }

    private function resolveAuditMonth(?string $month): CarbonImmutable
    {
        $firstMonth = CarbonImmutable::parse('2026-07-01', config('app.timezone'))->startOfMonth();
        $currentMonth = CarbonImmutable::now(config('app.timezone'))->startOfMonth();

        if (! is_string($month) || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return $currentMonth->greaterThanOrEqualTo($firstMonth) ? $currentMonth : $firstMonth;
        }

        try {
            $selectedMonth = CarbonImmutable::createFromFormat('!Y-m', $month, config('app.timezone'))->startOfMonth();
        } catch (Throwable) {
            return $currentMonth->greaterThanOrEqualTo($firstMonth) ? $currentMonth : $firstMonth;
        }

        if ($selectedMonth->lessThan($firstMonth)) {
            return $firstMonth;
        }

        return $selectedMonth->greaterThan($currentMonth) ? $currentMonth : $selectedMonth;
    }

    public function scopeLabel(string $scope): string
    {
        return match ($scope) {
            'commercials' => 'Comerciales',
            'delegations' => 'Delegaciones',
            'area_manager' => 'Área Manager',
            'financials' => 'Financieros',
            'call_center' => 'Call Center',
            'contact_center' => 'Contact Center',
            default => $scope,
        };
    }

    private function restrictedStatus(array $status): array
    {
        $technicalStatus = $status['status'] ?? null;

        return [
            'status' => $technicalStatus,
            'label' => match ($technicalStatus) {
                'pending_approval' => 'Pendiente de aprobación',
                'definitive' => 'Definitivo',
                'reopened' => 'Reabierto',
                default => 'Cálculo en curso',
            },
            'variant' => match ($technicalStatus) {
                'definitive' => 'group',
                'pending_approval', 'reopened' => 'pending',
                default => '',
            },
            'approved_by' => isset($status['approved_by']['name'])
                ? ['name' => $status['approved_by']['name']]
                : null,
            'approved_at' => $status['approved_at'] ?? null,
        ];
    }

    private function snapshotDashboard(array $snapshot, string $scope): array
    {
        return $snapshot[match ($scope) {
            'commercials' => 'commercials', 'delegations' => 'delegations', 'area_manager' => 'area_manager',
            'financials' => 'financials', 'call_center' => 'call_center', 'contact_center' => 'contact_center',
        }] ?? [];
    }

    private function finalRows(array $dashboard, string $scope): array
    {
        $source = $scope === 'delegations' ? ($dashboard['delegation_rows'] ?? []) : ($dashboard['summary_rows'] ?? []);
        $rows = collect($source)->map(function (array $row) use ($scope): array {
            $managerCount = (int) ($row['store_manager_distinct_count'] ?? 0);

            $projected = [
                'name' => $row['commercial_name'] ?? $row['delegation_name'] ?? $row['manager_name'] ?? $row['responsible_name'] ?? $row['summary_label'] ?? $row['agent_name'] ?? $row['captador_name'] ?? 'Sin identificar',
                'final_total' => (float) ($row['final_commission'] ?? $row['total_commission'] ?? $row['final_total'] ?? 0),
                'alert' => $scope === 'delegations' && $managerCount > 2 ? ($row['store_manager_alert'] ?? null) : null,
            ];

            return $scope === 'delegations'
                ? $projected + ['manager_name' => $row['store_manager_name'] ?? null]
                : $projected;
        });

        if ($scope === 'area_manager' && is_array($dashboard['commercial_director'] ?? null)) {
            $director = $dashboard['commercial_director'];
            $rows->push(['name' => $director['name'] ?? 'Dirección Comercial', 'final_total' => (float) ($director['final_total'] ?? 0), 'alert' => null]);
        }

        return $rows->values()->all();
    }
}
