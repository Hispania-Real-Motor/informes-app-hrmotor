<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionClosureEvent;
use App\Models\CommercialCommissionSnapshot;
use App\Models\ReportUser;
use App\Services\Reports\AreaManagerCommissions\AreaManagerCommissionDashboardService;
use App\Services\Reports\CallCenterCommissions\CallCenterCommissionDashboardService;
use App\Services\Reports\ContactCenterCommissions\ContactCenterCommissionDashboardService;
use App\Services\Reports\FinancialCommissions\FinancialCommissionDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommercialCommissionClosureService
{
    public const REQUIRED_COMPONENTS = ['sales', 'purchases', 'cancellations', 'reviews', 'adjustments'];

    public const REQUIRED_COMPONENTS_BY_SCOPE = [
        CommercialCommissionClosure::SCOPE_COMMERCIALS => self::REQUIRED_COMPONENTS,
        CommercialCommissionClosure::SCOPE_DELEGATIONS => ['sales', 'reviews'],
        CommercialCommissionClosure::SCOPE_AREA_MANAGER => ['sales', 'objectives'],
        CommercialCommissionClosure::SCOPE_FINANCIALS => ['sales', 'incentives'],
        CommercialCommissionClosure::SCOPE_CALL_CENTER => ['sales', 'tasaciones'],
        CommercialCommissionClosure::SCOPE_CONTACT_CENTER => ['leads', 'sales'],
    ];

    public const COMPONENT_LABELS = [
        'sales' => 'Ventas/oportunidades',
        'purchases' => 'Compras',
        'cancellations' => 'Cancelaciones',
        'reviews' => 'Reseñas',
        'adjustments' => 'Ajustes',
        'objectives' => 'Objetivos mensuales',
        'incentives' => 'Tramos de incentivos',
        'tasaciones' => 'Tasaciones',
        'leads' => 'Leads/citas',
    ];

    public function __construct(
        private readonly CommissionMonthResolver $monthResolver,
        private readonly CommercialCommissionDashboardService $commercials,
        private readonly AreaManagerCommissionDashboardService $areaManager,
        private readonly CallCenterCommissionDashboardService $callCenter,
        private readonly ContactCenterCommissionDashboardService $contactCenter,
        private readonly FinancialCommissionDashboardService $financials,
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
        private readonly CommercialCommissionSourceReadinessService $sourceReadiness,
    ) {}

    /** @return array<string, mixed> */
    public function status(?string $month, string $scope = CommercialCommissionClosure::SCOPE_COMMERCIALS): array
    {
        $this->assertClosableScope($scope);
        $selected = $this->monthResolver->resolve($month);
        $this->assertScopeAvailableForMonth($scope, $selected);
        $monthKey = $selected->format('Y-m');
        $closure = CommercialCommissionClosure::query()
            ->with(['approver:id,name', 'reopener:id,name'])
            ->where(['month' => $monthKey, 'closure_scope' => $scope])
            ->first();

        return $this->statusPayload($selected, $scope, $closure);
    }

    /** @return array<string, array<string, mixed>> */
    public function statuses(?string $month): array
    {
        $selected = $this->monthResolver->resolve($month);

        return collect($this->availableScopes($selected))
            ->mapWithKeys(fn (string $scope): array => [$scope => $this->status($month, $scope)])
            ->all();
    }

    public function availableScopes(CarbonImmutable|string $month): array
    {
        $selected = $month instanceof CarbonImmutable ? $month->startOfMonth() : $this->monthResolver->resolve($month);

        return $selected->lt(CarbonImmutable::parse(CommercialCommissionClosure::EXTENDED_SCOPES_START))
            ? CommercialCommissionClosure::LEGACY_SCOPES
            : CommercialCommissionClosure::CLOSABLE_SCOPES;
    }

    /** @return array<string, string> */
    public function requiredComponents(string $scope): array
    {
        $this->assertClosableScope($scope);

        return collect(self::REQUIRED_COMPONENTS_BY_SCOPE[$scope])
            ->mapWithKeys(fn (string $component): array => [$component => self::COMPONENT_LABELS[$component] ?? $component])
            ->all();
    }

    public function prepare(string $month, string $scope, array|ReportUser $legacyComponentsOrUser, ?ReportUser $user = null): CommercialCommissionClosure
    {
        $user ??= $legacyComponentsOrUser instanceof ReportUser ? $legacyComponentsOrUser : null;
        if (! $user instanceof ReportUser) {
            throw ValidationException::withMessages(['user' => 'No se ha podido resolver el usuario que prepara el cierre.']);
        }
        $this->assertClosableScope($scope);
        $selected = $this->monthResolver->resolve($month);
        $this->assertScopeAvailableForMonth($scope, $selected);
        $this->assertNaturalMonthFinished($selected);
        $candidate = $this->buildSnapshotPayload($selected->format('Y-m'), $scope);
        if (! $candidate['readiness']['ready']) {
            throw ValidationException::withMessages([
                'sources' => $candidate['readiness']['blocking'],
            ]);
        }
        $components = collect($candidate['readiness']['components'])
            ->map(fn (array $component): bool => ! $component['blocking'])
            ->all();

        return DB::transaction(function () use ($selected, $scope, $components, $candidate, $user): CommercialCommissionClosure {
            $monthKey = $selected->format('Y-m');
            $closure = CommercialCommissionClosure::query()
                ->where(['month' => $monthKey, 'closure_scope' => $scope])
                ->lockForUpdate()
                ->first();
            if ($closure?->status === CommercialCommissionClosure::STATUS_DEFINITIVE) {
                throw ValidationException::withMessages(['month' => 'El bloque es definitivo. Debe reabrirse antes de preparar un nuevo cierre.']);
            }
            if ($closure === null) {
                $closure = new CommercialCommissionClosure(['month' => $monthKey, 'closure_scope' => $scope]);
            }
            $from = $closure->exists ? $closure->status : null;
            $closure->fill([
                'status' => CommercialCommissionClosure::STATUS_PENDING_APPROVAL,
                'component_statuses' => $components,
                'issues' => $candidate['readiness']['warnings'],
                'data_cutoff_at' => $candidate['data_cutoff_at'],
                'formula_version' => CommercialCommissionFormulaConfigService::VERSION,
            ])->save();
            $version = (int) $closure->snapshot_version + 1;
            CommercialCommissionSnapshot::query()->create([
                'closure_id' => $closure->id,
                'month' => $monthKey,
                'version' => $version,
                'formula_version' => CommercialCommissionFormulaConfigService::VERSION,
                'data_cutoff_at' => $candidate['data_cutoff_at'],
                'payload' => $candidate['dashboard'],
                'source_state' => $candidate['source_state'],
                'created_by' => $user->id,
                'created_at' => now(),
            ]);
            $closure->update(['snapshot_version' => $version]);
            $this->event($closure, 'prepared', $from, $closure->status, $user, null, [
                'closure_scope' => $scope,
                'snapshot_version' => $version,
                'source_state' => $candidate['source_state'],
            ]);

            return $closure->fresh();
        });
    }

    public function approve(string $month, string $scope, ReportUser $user): CommercialCommissionClosure
    {
        $this->assertClosableScope($scope);
        $selected = $this->monthResolver->resolve($month);
        $this->assertScopeAvailableForMonth($scope, $selected);
        $this->assertNaturalMonthFinished($selected);
        $monthKey = $selected->format('Y-m');

        return DB::transaction(function () use ($monthKey, $scope, $user): CommercialCommissionClosure {
            $closure = CommercialCommissionClosure::query()->where(['month' => $monthKey, 'closure_scope' => $scope])->lockForUpdate()->first();
            if (! $closure || $closure->status !== CommercialCommissionClosure::STATUS_PENDING_APPROVAL) {
                throw ValidationException::withMessages(['month' => 'El bloque debe estar pendiente de aprobación antes de hacerlo definitivo.']);
            }
            if (collect(self::REQUIRED_COMPONENTS_BY_SCOPE[$scope])->contains(fn (string $component): bool => ! data_get($closure->component_statuses, $component, false))) {
                throw ValidationException::withMessages(['components' => 'Faltan componentes confirmados para este bloque.']);
            }
            $version = (int) $closure->snapshot_version;
            $candidate = $closure->snapshots()->where('version', $version)->first();
            if ($candidate === null) {
                throw ValidationException::withMessages(['snapshot' => 'No existe el candidato preparado. Administración debe preparar de nuevo el bloque.']);
            }
            $from = $closure->status;
            $closure->update([
                'status' => CommercialCommissionClosure::STATUS_DEFINITIVE,
                'data_cutoff_at' => $candidate->data_cutoff_at, 'formula_version' => $candidate->formula_version,
                'approved_by' => $user->id, 'approved_at' => now(), 'reopened_by' => null, 'reopened_at' => null,
                'reopen_reason' => null, 'snapshot_version' => $version,
            ]);
            $this->event($closure, 'approved', $from, $closure->status, $user, null, ['closure_scope' => $scope, 'snapshot_version' => $version]);

            return $closure->fresh();
        });
    }

    public function reopen(string $month, string $scope, string $reason, ReportUser $user): CommercialCommissionClosure
    {
        $this->assertClosableScope($scope);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'El motivo de reapertura es obligatorio y debe ser suficientemente descriptivo.']);
        }

        return DB::transaction(function () use ($month, $scope, $reason, $user): CommercialCommissionClosure {
            $selected = $this->monthResolver->resolve($month);
            $this->assertScopeAvailableForMonth($scope, $selected);
            $monthKey = $selected->format('Y-m');
            $closure = CommercialCommissionClosure::query()->where(['month' => $monthKey, 'closure_scope' => $scope])->lockForUpdate()->first();
            if (! $closure || $closure->status !== CommercialCommissionClosure::STATUS_DEFINITIVE) {
                throw ValidationException::withMessages(['month' => 'Solo se puede reabrir un bloque definitivo.']);
            }
            $from = $closure->status;
            $closure->update(['status' => CommercialCommissionClosure::STATUS_REOPENED, 'reopened_by' => $user->id, 'reopened_at' => now(), 'reopen_reason' => $reason]);
            $this->event($closure, 'reopened', $from, $closure->status, $user, $reason, ['closure_scope' => $scope, 'snapshot_version' => $closure->snapshot_version]);

            return $closure->fresh();
        });
    }

    /** @return array<string, mixed>|null */
    public function definitiveSnapshot(?string $month, string $scope = CommercialCommissionClosure::SCOPE_COMMERCIALS): ?array
    {
        $this->assertClosableScope($scope);
        $selected = $this->monthResolver->resolve($month);
        $this->assertScopeAvailableForMonth($scope, $selected);
        $closure = CommercialCommissionClosure::query()
            ->where(['month' => $selected->format('Y-m'), 'closure_scope' => $scope, 'status' => CommercialCommissionClosure::STATUS_DEFINITIVE])
            ->first();

        return $closure?->snapshots()->where('version', $closure->snapshot_version)->first()?->payload;
    }

    /** @return array<string, mixed>|null */
    public function candidateOrDefinitiveSnapshot(?string $month, string $scope): ?array
    {
        $this->assertClosableScope($scope);
        $selected = $this->monthResolver->resolve($month);
        $this->assertScopeAvailableForMonth($scope, $selected);
        $closure = CommercialCommissionClosure::query()
            ->where('month', $selected->format('Y-m'))
            ->where('closure_scope', $scope)
            ->whereIn('status', [CommercialCommissionClosure::STATUS_PENDING_APPROVAL, CommercialCommissionClosure::STATUS_DEFINITIVE])
            ->first();

        return $closure?->snapshots()->where('version', $closure->snapshot_version)->first()?->payload;
    }

    public function nextOpenMonth(?string $requested = null, string $scope = CommercialCommissionClosure::SCOPE_COMMERCIALS): string
    {
        $this->assertClosableScope($scope);
        $month = $this->monthResolver->resolve($requested);
        $this->assertScopeAvailableForMonth($scope, $month);
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $status = CommercialCommissionClosure::query()->where(['month' => $month->format('Y-m'), 'closure_scope' => $scope])->value('status');
            if ($status !== CommercialCommissionClosure::STATUS_DEFINITIVE) {
                return $month->format('Y-m');
            }
            $month = $month->addMonthNoOverflow();
        }
        throw ValidationException::withMessages(['application_month' => 'No se encontró un mes económico abierto.']);
    }

    /** @return array<string, mixed> */
    public function approvalSummary(?string $month, string $scope): array
    {
        $this->assertClosableScope($scope);
        $monthKey = $this->monthResolver->resolve($month)->format('Y-m');
        $status = $this->status($monthKey, $scope);
        $snapshot = $this->candidateOrDefinitiveSnapshot($monthKey, $scope);
        $dashboard = $snapshot !== null
            ? $this->dashboardFromSnapshot($snapshot, $scope)
            : $this->buildLiveDashboard($monthKey, $scope, false);

        return [
            'scope' => $scope,
            'label' => $this->scopeLabel($scope),
            'month' => $monthKey,
            'final_total' => $this->finalTotal($dashboard, $scope),
            'status' => $status,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function approvalOverview(?string $month, ?string $knownScope = null, ?array $knownDashboard = null, ?array $allowedScopes = null): array
    {
        $monthKey = $this->monthResolver->resolve($month)->format('Y-m');

        return collect($this->availableScopes($monthKey))->filter(fn (string $scope): bool => $allowedScopes === null || in_array($scope, $allowedScopes, true))->map(function (string $scope) use ($monthKey): array {
            $status = $this->status($monthKey, $scope);
            $dashboard = null;

            if ($status['is_prepared'] && in_array($status['status'], [CommercialCommissionClosure::STATUS_PENDING_APPROVAL, CommercialCommissionClosure::STATUS_DEFINITIVE], true)) {
                $snapshot = $this->candidateOrDefinitiveSnapshot($monthKey, $scope);
                $dashboard = $snapshot === null ? null : $this->dashboardFromSnapshot($snapshot, $scope);
            }

            return [
                'scope' => $scope,
                'label' => $this->scopeLabel($scope),
                'month' => $monthKey,
                'final_total' => $dashboard === null ? null : $this->finalTotal($dashboard, $scope),
                'status' => $status,
                'alerts' => $scope === CommercialCommissionClosure::SCOPE_DELEGATIONS && $dashboard !== null
                    ? $this->delegationManagerAlerts($dashboard)
                    : [],
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function preparationOverview(?string $month, ?string $knownScope = null, ?array $knownDashboard = null): array
    {
        $monthKey = $this->monthResolver->resolve($month)->format('Y-m');

        return collect($this->availableScopes($monthKey))->map(function (string $scope) use ($monthKey, $knownScope, $knownDashboard): array {
            $status = $this->status($monthKey, $scope);
            $snapshotModel = null;
            if ($status['is_prepared']) {
                $closure = CommercialCommissionClosure::query()->where(['month' => $monthKey, 'closure_scope' => $scope])->first();
                $snapshotModel = $closure?->snapshots()->where('version', $closure->snapshot_version)->first();
            }
            $readiness = $knownScope === $scope && $knownDashboard !== null
                ? $this->sourceReadiness->inspect($scope, $monthKey, $knownDashboard)
                : null;

            return [
                'scope' => $scope,
                'label' => $this->scopeLabel($scope),
                'month' => $monthKey,
                'status' => $status,
                'readiness' => $readiness,
                'prepared_source_state' => $snapshotModel?->source_state ?? [],
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function buildLiveDashboard(string $month, string $scope, bool $includeDetails = true): array
    {
        $this->assertClosableScope($scope);

        return match ($scope) {
            CommercialCommissionClosure::SCOPE_COMMERCIALS => $this->commercials->build($month, true, false, $includeDetails),
            CommercialCommissionClosure::SCOPE_DELEGATIONS => $this->commercials->build($month, false, true, false),
            CommercialCommissionClosure::SCOPE_AREA_MANAGER => $this->areaManager->build($month),
            CommercialCommissionClosure::SCOPE_FINANCIALS => $this->financials->build($month),
            CommercialCommissionClosure::SCOPE_CALL_CENTER => $this->callCenter->build($month, includeDetails: $includeDetails),
            CommercialCommissionClosure::SCOPE_CONTACT_CENTER => $this->contactCenter->build($month, includeDetails: $includeDetails),
        };
    }

    /** @return array{dashboard: array<string, mixed>, issues: array<int, string>, data_cutoff_at: CarbonImmutable, source_state: array<string, mixed>, readiness: array<string, mixed>} */
    private function buildSnapshotPayload(string $month, string $scope): array
    {
        $capturedAt = CarbonImmutable::now(config('app.timezone'));
        $dashboard = match ($scope) {
            CommercialCommissionClosure::SCOPE_COMMERCIALS => [
                'commercials' => $this->buildLiveDashboard($month, $scope),
                'formula_settings' => $this->formulaConfig->forMonth($month),
                'review_audit' => $this->commercials->reviewAudit($month),
            ],
            CommercialCommissionClosure::SCOPE_DELEGATIONS => [
                'delegations' => $this->buildLiveDashboard($month, $scope),
                'formula_settings' => $this->formulaConfig->forMonth($month),
            ],
            CommercialCommissionClosure::SCOPE_AREA_MANAGER => $this->areaManagerSnapshot($month),
            CommercialCommissionClosure::SCOPE_FINANCIALS => ['financials' => $this->buildLiveDashboard($month, $scope)],
            CommercialCommissionClosure::SCOPE_CALL_CENTER => ['call_center' => $this->buildLiveDashboard($month, $scope)],
            CommercialCommissionClosure::SCOPE_CONTACT_CENTER => ['contact_center' => $this->buildLiveDashboard($month, $scope)],
        };
        $issues = collect($dashboard)
            ->flatMap(fn (mixed $value): array => is_array($value) ? ($value['issues'] ?? []) : [])
            ->map(fn (mixed $issue): string => trim((string) $issue))
            ->filter()
            ->unique()->values()->all();
        $readiness = $this->sourceReadiness->inspect($scope, $month, $this->dashboardFromSnapshot($dashboard, $scope));
        if ($issues !== []) {
            $readiness['ready'] = false;
            $readiness['blocking'] = collect($readiness['blocking'])->merge($issues)->unique()->values()->all();
        }

        return [
            'dashboard' => $dashboard,
            'issues' => $issues,
            'data_cutoff_at' => $capturedAt,
            'source_state' => $readiness['source_state'],
            'readiness' => $readiness,
        ];
    }

    /** @return array<string, mixed> */
    private function areaManagerSnapshot(string $month): array
    {
        $byZone = ReportUser::query()->whereIn('role', [ReportUser::ROLE_AREA_MANAGER, ReportUser::LEGACY_ROLE_AREA_MANAGER_OWN_AREA])
            ->whereNotNull('area_zone')->pluck('area_zone')->unique()
            ->mapWithKeys(function (string $zoneKey) use ($month): array {
                $label = ReportUser::areaZoneLabel($zoneKey);

                return $label ? [$zoneKey => $this->areaManager->build($month, $label)] : [];
            })->all();

        return ['area_manager' => $this->areaManager->build($month), 'area_manager_by_zone' => $byZone, 'formula_settings' => $this->formulaConfig->forMonth($month)];
    }

    /** @return array<string, mixed> */
    private function statusPayload(CarbonImmutable $selected, string $scope, ?CommercialCommissionClosure $closure): array
    {
        $default = $selected->isCurrentMonth() ? CommercialCommissionClosure::STATUS_PROVISIONAL : CommercialCommissionClosure::STATUS_PENDING_APPROVAL;

        return [
            'month' => $selected->format('Y-m'), 'closure_scope' => $scope, 'status' => $closure?->status ?? $default,
            'is_prepared' => $closure !== null,
            'is_current_month' => $selected->isCurrentMonth(), 'component_statuses' => $closure?->component_statuses ?? array_fill_keys(self::REQUIRED_COMPONENTS_BY_SCOPE[$scope], false),
            'required_components' => array_keys($this->requiredComponents($scope)),
            'component_labels' => $this->requiredComponents($scope),
            'issues' => $closure?->issues ?? [], 'data_cutoff_at' => $closure?->data_cutoff_at?->toIso8601String(),
            'formula_version' => $closure?->formula_version ?? CommercialCommissionFormulaConfigService::VERSION,
            'approved_by' => $closure?->approver ? ['id' => $closure->approver->id, 'name' => $closure->approver->name] : null,
            'approved_by_name' => $closure?->approver?->name,
            'approved_at' => $closure?->approved_at?->toIso8601String(), 'reopened_by' => $closure?->reopener ? ['id' => $closure->reopener->id, 'name' => $closure->reopener->name] : null,
            'reopened_at' => $closure?->reopened_at?->toIso8601String(), 'reopen_reason' => $closure?->reopen_reason,
            'snapshot_version' => (int) ($closure?->snapshot_version ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function dashboardFromSnapshot(array $snapshot, string $scope): array
    {
        return match ($scope) {
            CommercialCommissionClosure::SCOPE_COMMERCIALS => $snapshot['commercials'] ?? [],
            CommercialCommissionClosure::SCOPE_DELEGATIONS => $snapshot['delegations'] ?? [],
            CommercialCommissionClosure::SCOPE_AREA_MANAGER => $snapshot['area_manager'] ?? [],
            CommercialCommissionClosure::SCOPE_FINANCIALS => $snapshot['financials'] ?? [],
            CommercialCommissionClosure::SCOPE_CALL_CENTER => $snapshot['call_center'] ?? [],
            CommercialCommissionClosure::SCOPE_CONTACT_CENTER => $snapshot['contact_center'] ?? [],
        };
    }

    /** @return array<int, string> */
    private function delegationManagerAlerts(array $dashboard): array
    {
        return collect($dashboard['delegation_rows'] ?? [])
            ->filter(fn (array $row): bool => (int) ($row['store_manager_distinct_count'] ?? 0) > 2)
            ->pluck('store_manager_alert')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function finalTotal(array $dashboard, string $scope): float
    {
        return round((float) match ($scope) {
            CommercialCommissionClosure::SCOPE_COMMERCIALS => collect($dashboard['summary_rows'] ?? [])->sum('final_commission'),
            CommercialCommissionClosure::SCOPE_DELEGATIONS => collect($dashboard['delegation_rows'] ?? [])->sum('total_commission'),
            CommercialCommissionClosure::SCOPE_AREA_MANAGER => collect($dashboard['summary_rows'] ?? [])->sum('final_total')
                + (float) data_get($dashboard, 'commercial_director.final_total', 0),
            CommercialCommissionClosure::SCOPE_FINANCIALS => collect($dashboard['summary_rows'] ?? [])->sum('final_commission'),
            CommercialCommissionClosure::SCOPE_CALL_CENTER, CommercialCommissionClosure::SCOPE_CONTACT_CENTER => collect($dashboard['summary_rows'] ?? [])->sum('final_total'),
        }, 2);
    }

    private function scopeLabel(string $scope): string
    {
        return match ($scope) {
            CommercialCommissionClosure::SCOPE_COMMERCIALS => 'Comerciales',
            CommercialCommissionClosure::SCOPE_DELEGATIONS => 'Delegaciones',
            CommercialCommissionClosure::SCOPE_AREA_MANAGER => 'Área Manager',
            CommercialCommissionClosure::SCOPE_FINANCIALS => 'Financieros',
            CommercialCommissionClosure::SCOPE_CALL_CENTER => 'Call Center',
            CommercialCommissionClosure::SCOPE_CONTACT_CENTER => 'Contact Center',
        };
    }

    private function assertClosableScope(string $scope): void
    {
        if (! in_array($scope, CommercialCommissionClosure::CLOSABLE_SCOPES, true)) {
            throw ValidationException::withMessages(['closure_scope' => 'El bloque de cierre no es válido.']);
        }
    }

    private function assertScopeAvailableForMonth(string $scope, CarbonImmutable $month): void
    {
        if (! in_array($scope, $this->availableScopes($month), true)) {
            throw ValidationException::withMessages([
                'closure_scope' => 'Este bloque de cierre solo está disponible desde julio de 2026.',
            ]);
        }
    }

    private function assertNaturalMonthFinished(CarbonImmutable $month): void
    {
        if ($month->greaterThanOrEqualTo(CarbonImmutable::now(config('app.timezone'))->startOfMonth())) {
            throw ValidationException::withMessages(['month' => 'El mes natural debe haber terminado antes de poder cerrarse.']);
        }
    }

    /** @param array<string, mixed> $context */
    private function event(CommercialCommissionClosure $closure, string $action, ?string $from, string $to, ReportUser $user, ?string $reason = null, array $context = []): void
    {
        CommercialCommissionClosureEvent::query()->create(['closure_id' => $closure->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to, 'report_user_id' => $user->id, 'reason' => $reason, 'context' => $context, 'created_at' => now()]);
    }
}
