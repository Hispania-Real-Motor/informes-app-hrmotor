<?php

namespace App\Services\Reports\Leads;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestActivity;
use App\Models\SalesforceInterestActivityRun;
use App\Models\SalesforceUser;
use App\Services\Salesforce\SalesforceInterestSyncService;
use App\Support\ReportServerTiming;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

final class SalesforceInterestDashboardDatasetService extends SalesforceLeadDashboardDatasetService
{
    private const COMMERCIAL_PROFILES = ['Compra/Venta', 'Comerciales Partner Community'];

    private const TECHNICAL_OWNER_IDS = [
        '0052X00000AP4U5QAL',
        '0057R00000AKkz0QAD',
        '0057R00000CQGZaQAP',
    ];

    private const TECHNICAL_OWNER_NAMES = ['admin adesso', 'api user', 'carlos torres'];

    private ?array $sourceContext = null;

    private ?Collection $interestCommercialUsers = null;

    public function __construct(
        private readonly LeadDelegationNormalizer $interestDelegationNormalizer,
        private readonly LeadRecordTypeNormalizer $interestRecordTypeNormalizer,
        LeadDashboardAiInsightsService $aiInsights,
        LeadPortalResolver $portalResolver,
        ?LeadClassificationResolver $classificationResolver = null,
    ) {
        parent::__construct(
            $interestDelegationNormalizer,
            $aiInsights,
            $interestRecordTypeNormalizer,
            $portalResolver,
            $classificationResolver,
        );
    }

    public function payload(Request $request, string $context = 'summary', ?ReportServerTiming $timing = null): array
    {
        $this->sourceContext = $this->resolveAlignedContext();
        $payload = parent::payload($request, $context, $timing);
        $this->assertContextStillCurrent($this->sourceContext);
        if (($payload['summary']['empty'] ?? false) === true) {
            $payload['summary']['message'] = 'No hay Interests que coincidan con el periodo y los filtros seleccionados.';
        }
        $payload['summary']['functional_source'] = 'salesforce_interests';
        $payload['summary']['compatibility_aliases'] = ['portal' => 'source'];
        $payload['summary']['comparativa'] = collect($payload['summary']['comparativa'] ?? [])
            ->map(function (array $item): array {
                $item['label'] = $this->interestVocabulary((string) ($item['label'] ?? ''));

                return $item;
            })->all();
        foreach (['insights', 'executive_insights'] as $key) {
            $payload['summary'][$key] = collect($payload['summary'][$key] ?? [])->map(function (array $item): array {
                foreach (['titulo', 'problema_detectado', 'evidencia', 'recomendacion'] as $field) {
                    if (isset($item[$field])) {
                        $item[$field] = $this->interestVocabulary($item[$field]);
                    }
                }

                return $item;
            })->all();
        }

        return $payload;
    }

    public function executiveLeadTotal(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $this->sourceContext = $this->resolveAlignedContext();
        $result = parent::executiveLeadTotal($start, $end);
        $this->assertContextStillCurrent($this->sourceContext);

        return $result;
    }

    public function kpiAudit(Request $request): array
    {
        $this->sourceContext = $this->resolveAlignedContext();
        $result = parent::kpiAudit($request);
        $this->assertContextStillCurrent($this->sourceContext);

        $result['items'] = collect($result['items'] ?? [])->map(function (array $item): array {
            $item['interest_id'] = $item['lead_id'] ?? null;
            $item['functional_created_at'] = $item['created_date'] ?? null;
            $item['source'] = $item['portal'] ?? null;

            return collect($item)->except([
                'lead_id', 'lead_name', 'phone', 'mobile_phone', 'email',
                'persona_que_trabajo_id', 'persona_que_trabajo_name',
                'propietario_descarte_id', 'propietario_descarte_name',
                'campaign_acquired', 'acquired_id', 'content_acquired',
            ])->all();
        })->all();
        $result['metric_label'] = $this->interestVocabulary((string) ($result['metric_label'] ?? ''));

        return $result;
    }

    public function leadAudit(array $salesforceIds, ?Request $request = null): array
    {
        $this->sourceContext = $this->resolveAlignedContext();
        $ids = collect($salesforceIds)->map(fn (mixed $id) => trim((string) $id))->filter()->unique()->take(200)->values();
        $rows = SalesforceInterest::query()->whereIn('salesforce_id', $ids)->get()->keyBy('salesforce_id');
        $summaries = $this->activitySummaries($this->sourceContext['f5']->id, $ids->all());
        $filters = $request ? $this->filters($request, 'summary') : null;

        $result = [
            'ok' => true,
            'items' => $ids->map(function (string $id) use ($rows, $summaries, $filters): ?array {
                /** @var SalesforceInterest|null $row */
                $row = $rows->get($id);
                if ($row === null) {
                    return ['salesforce_id' => $id, 'exists_local' => false, 'salesforce_state' => 'not_synchronized'];
                }

                $decorated = $this->decorateLead($row, $summaries->get($id));
                if ($filters !== null && ! $this->passesAccessScopeInterest($decorated, $filters)) {
                    return null;
                }

                return [
                    'salesforce_id' => $id,
                    'exists_local' => true,
                    'salesforce_state' => $row->is_deleted ? 'deleted' : 'active_at_last_sync',
                    'functional_created_at' => $this->auditDateValue($row->functional_created_at),
                    'salesforce_created_at' => $this->auditDateValue($row->salesforce_created_at),
                    'status' => $row->status,
                    'type' => $row->type,
                    'source' => $row->source,
                    'original_source' => $row->original_source,
                    'medium' => $row->medium,
                    'channel' => $row->channel,
                    'origin_delegation' => $row->origin_delegation,
                    'owner_salesforce_id' => $row->owner_salesforce_id,
                    'owner_name' => $row->owner_name,
                    'is_deleted' => (bool) $row->is_deleted,
                    'salesforce_deleted_at' => $this->auditDateValue($row->salesforce_deleted_at),
                    'deletion_detection_source' => $row->deletion_detection_source,
                    'total_direct_activities' => $decorated['total_actividades'],
                    'last_functional_activity_at' => $this->auditDateValue($decorated['fecha_ultima_actividad']),
                    'source_interest_sync_run_id' => $this->sourceContext['f2']->id,
                    'source_activity_run_id' => $this->sourceContext['f5']->id,
                ];
            })->filter()->values()->all(),
        ];
        $this->assertContextStillCurrent($this->sourceContext);

        return $result;
    }

    public function reconciliationAudit(Request $request): array
    {
        $this->sourceContext = $this->resolveAlignedContext();
        $filters = $this->filters($request, 'summary');
        $period = $this->periods($filters)['current'];
        $items = [];

        SalesforceInterest::query()
            ->where('functional_created_at', '>=', $period['start'])
            ->where('functional_created_at', '<=', $period['end'])
            ->orderBy('id')
            ->chunkById(1000, function (Collection $rows) use (&$items, $filters): void {
                $summaries = $this->activitySummaries($this->sourceContext['f5']->id, $rows->pluck('salesforce_id')->all());
                foreach ($rows as $row) {
                    $interest = $this->decorateLead($row, $summaries->get($row->salesforce_id));
                    if (! $this->passesAccessScopeInterest($interest, $filters)) {
                        continue;
                    }
                    $items[] = [
                        'interest_id' => $interest['salesforce_id'],
                        'functional_created_at' => $this->auditDateValue($interest['created_date']),
                        'salesforce_created_at' => $this->auditDateValue($interest['salesforce_created_at']),
                        'status' => $interest['status'],
                        'type_raw' => $interest['lead_type_raw'],
                        'type_normalized' => $interest['lead_type_normalized'],
                        'source' => $interest['portal'],
                        'original_source' => $interest['original_source'],
                        'medium' => $interest['medio_efectivo'],
                        'channel' => $interest['canal'],
                        'origin_delegation_raw' => $interest['lead_delegation_raw'],
                        'origin_delegation_normalized' => $interest['lead_delegation'],
                        'owner_id' => $interest['owner_id'],
                        'owner_name' => $interest['owner_name'],
                        'effective_commercial_id' => $interest['gestor_id'],
                        'effective_commercial_name' => $interest['gestor_nombre'],
                        'commercial_delegation' => $interest['commercial_delegation'],
                        'commercial_zone' => $interest['commercial_zone'],
                        'is_deleted' => $interest['is_deleted'],
                        'total_direct_activities' => $interest['total_actividades'],
                        'last_functional_activity_at' => $this->auditDateValue($interest['fecha_ultima_actividad']),
                        'source_interest_sync_run_id' => $this->sourceContext['f2']->id,
                        'source_activity_run_id' => $this->sourceContext['f5']->id,
                        'included_in_active_dataset' => true,
                    ];
                }
            });

        $this->assertContextStillCurrent($this->sourceContext);

        return $items;
    }

    public function decorateLead(mixed $interest, mixed $summary = null, ?CarbonInterface $referenceDate = null): array
    {
        $referenceDate = $referenceDate ? CarbonImmutable::parse($referenceDate) : CarbonImmutable::now();
        $status = trim((string) data_get($interest, 'status'));
        $typeRaw = $this->cleanValue(data_get($interest, 'type'));
        $typeNormalized = $this->interestRecordTypeNormalizer->normalize($typeRaw);
        $originDelegation = $this->interestDelegationNormalizer->normalize($this->cleanValue(data_get($interest, 'origin_delegation')));
        $ownerId = $this->cleanValue(data_get($interest, 'owner_salesforce_id'));
        $ownerName = $this->cleanValue(data_get($interest, 'owner_name'));
        $commercial = $ownerId ? $this->commercialUsers()->get($ownerId) : null;
        $commercialDelegation = $this->interestDelegationNormalizer->normalize(data_get($commercial, 'user_delegation'));
        if (str_ends_with($commercialDelegation['delegation'], ' General')) {
            $commercialDelegation = $this->interestDelegationNormalizer->normalize(null);
        }
        $totalActivities = (int) (data_get($summary, 'total_actividades') ?? 0);
        $lastActivity = data_get($summary, 'fecha_ultima_actividad');
        $lastActivityAt = $lastActivity ? CarbonImmutable::parse($lastActivity) : null;
        $hasRecentActivity = $lastActivityAt !== null
            && $lastActivityAt->lessThanOrEqualTo($referenceDate)
            && $lastActivityAt->greaterThanOrEqualTo($referenceDate->subDays(3));
        $isPotential = $status === 'Potencial';
        $isUnassigned = $isPotential && $this->isTechnicalOwner($ownerId, $ownerName);
        $source = $this->cleanValue(data_get($interest, 'source')) ?? 'Sin clasificar';
        $channel = $this->cleanValue(data_get($interest, 'channel'));

        return [
            'id' => data_get($interest, 'id'),
            'salesforce_id' => data_get($interest, 'salesforce_id'),
            'lead_name' => null,
            'created_date' => data_get($interest, 'functional_created_at'),
            'salesforce_created_at' => data_get($interest, 'salesforce_created_at'),
            'status' => $status,
            'lead_type' => $typeRaw,
            'lead_type_raw' => $typeRaw,
            'lead_type_normalized' => $typeNormalized,
            'owner_id' => $ownerId,
            'owner_name' => $ownerName,
            'original_source' => data_get($interest, 'original_source'),
            'medio_efectivo' => data_get($interest, 'medium'),
            'is_convertido' => $status === 'Convertido',
            'is_descartado' => $status === 'Descartado',
            'is_potencial' => $isPotential,
            'is_potencial_sin_trabajar' => $isPotential && ! $isUnassigned && ($totalActivities === 0 || ! $hasRecentActivity),
            'is_lead_sin_asignar' => $isUnassigned,
            'is_gestionado' => in_array($status, ['Convertido', 'Descartado'], true) || ($isPotential && $hasRecentActivity),
            'is_llamada' => $channel === 'Llamada',
            'is_formulario' => $channel === 'Formulario',
            'canal' => $channel,
            'portal' => $source,
            'portal_resolution_source' => 'salesforce_interests.source',
            'grupo_portal' => $source,
            'lead_delegation' => $originDelegation['delegation'],
            'lead_group' => $originDelegation['group'],
            'lead_zone' => $originDelegation['zone'],
            'lead_delegation_raw' => $originDelegation['raw'],
            'lead_delegation_is_classified' => $originDelegation['is_classified'],
            'lead_delegation_effective_source' => 'salesforce_interests.origin_delegation',
            'lead_access_delegation' => $originDelegation['delegation'],
            'commercial_delegation' => $commercialDelegation['delegation'],
            'commercial_group' => $commercialDelegation['group'],
            'commercial_zone' => $commercialDelegation['zone'],
            'commercial_delegation_raw' => $commercialDelegation['raw'],
            'commercial_delegation_is_classified' => $commercialDelegation['is_classified'],
            'zona' => $commercialDelegation['zone'],
            'gestor_id' => $ownerId,
            'gestor_nombre' => data_get($commercial, 'name') ?? $ownerName,
            'gestor_es_comercial' => $commercial !== null,
            'is_without_eligible_commercial' => $commercial === null,
            'is_without_commercial_delegation' => $commercial !== null && ! $commercialDelegation['is_classified'],
            'is_unclassified' => ! $originDelegation['is_classified'],
            'is_exposicion' => false,
            'total_actividades' => $totalActivities,
            'fecha_ultima_actividad' => $lastActivityAt,
            'synced_at' => data_get($interest, 'synced_at'),
            'salesforce_last_modified_at' => data_get($interest, 'salesforce_last_modified_at'),
            'is_deleted' => (bool) data_get($interest, 'is_deleted', false),
            'salesforce_deleted_at' => data_get($interest, 'salesforce_deleted_at'),
            'deletion_detection_source' => data_get($interest, 'deletion_detection_source'),
        ];
    }

    protected function eachPeriodLead(array $period, callable $callback): void
    {
        $context = $this->sourceContext ??= $this->resolveAlignedContext();
        $referenceDate = CarbonImmutable::parse($period['end']);

        SalesforceInterest::query()
            ->where('is_deleted', false)
            ->where('functional_created_at', '>=', $period['start'])
            ->where('functional_created_at', '<=', $period['end'])
            ->orderBy('id')
            ->chunkById(1000, function (Collection $rows) use ($callback, $context, $referenceDate): void {
                $summaries = $this->activitySummaries($context['f5']->id, $rows->pluck('salesforce_id')->all());
                foreach ($rows as $row) {
                    $callback($this->decorateLead($row, $summaries->get($row->salesforce_id), $referenceDate));
                }
            });
    }

    protected function dataVersion(): array
    {
        $context = $this->sourceContext ??= $this->resolveAlignedContext();

        return [
            'interest_sync_run_id' => $context['f2']->id,
            'interest_cutoff' => $context['f2']->source_cutoff_at?->toIso8601String(),
            'activity_run_id' => $context['f5']->id,
            'activity_cutoff' => $context['f5']->source_cutoff_at?->toIso8601String(),
            'users_version' => SalesforceUser::query()->max('updated_at'),
        ];
    }

    protected function lastUpdated(): ?CarbonImmutable
    {
        $updated = SalesforceInterest::query()->max('updated_at');

        return $updated ? CarbonImmutable::parse($updated) : null;
    }

    protected function syncMetadata(array $period): array
    {
        $context = $this->sourceContext ??= $this->resolveAlignedContext();
        $query = SalesforceInterest::query()->where('is_deleted', false)
            ->where('functional_created_at', '>=', $period['start'])
            ->where('functional_created_at', '<=', $period['end']);

        return [
            'salesforce_leads_synced_at' => $context['f2']->source_cutoff_at?->toDateTimeString(),
            'activities_synced_at' => $context['f5']->source_cutoff_at?->toDateTimeString(),
            'dataset_generated_at' => now()->toDateTimeString(),
            'dataset_cutoff_at' => $context['f2']->source_cutoff_at?->toDateTimeString(),
            'period_start' => CarbonImmutable::parse($period['start'])->toDateTimeString(),
            'period_end' => CarbonImmutable::parse($period['end'])->toDateTimeString(),
            'timezone' => 'Europe/Madrid',
            'sync_run_id' => $context['f2']->id,
            'sync_run_status' => 'completed',
            'interest_sync_run_id' => $context['f2']->id,
            'interest_activity_run_id' => $context['f5']->id,
            'activity_alignment_status' => 'aligned',
            'metadata_coverage' => [
                'total' => (clone $query)->count(),
                'without_synced_at' => (clone $query)->whereNull('synced_at')->count(),
                'without_last_modified_at' => (clone $query)->whereNull('salesforce_last_modified_at')->count(),
            ],
        ];
    }

    private function activitySummaries(int $runId, array $interestIds): Collection
    {
        return SalesforceInterestActivity::query()
            ->where('activity_run_id', $runId)
            ->where('relationship_status', 'resolved')
            ->where('activity_is_deleted', false)
            ->whereIn('interest_salesforce_id', $interestIds)
            ->get(['interest_salesforce_id', 'activity_kind', 'activity_date', 'start_datetime', 'salesforce_created_at'])
            ->groupBy('interest_salesforce_id')
            ->map(function (Collection $activities): array {
                $dated = $activities->map(function (SalesforceInterestActivity $activity): ?CarbonImmutable {
                    if ($activity->activity_kind === 'Task' && $activity->activity_date !== null) {
                        $date = CarbonImmutable::parse($activity->activity_date)->startOfDay();

                        return $activity->salesforce_created_at
                            ? $date->setTimeFrom(CarbonImmutable::parse($activity->salesforce_created_at))
                            : $date;
                    }

                    return $activity->activity_kind === 'Event' && $activity->start_datetime !== null
                        ? CarbonImmutable::parse($activity->start_datetime)
                        : null;
                })->filter();

                return [
                    'total_actividades' => $activities->count(),
                    'fecha_ultima_actividad' => $dated->sortByDesc(fn (CarbonImmutable $date) => $date->getTimestamp())->first(),
                ];
            });
    }

    private function resolveAlignedContext(): array
    {
        $f2 = ReportSyncRun::query()
            ->where('dataset', SalesforceInterestSyncService::DATASET)
            ->where('source', SalesforceInterestSyncService::SOURCE)
            ->latest('id')->first();
        if ($f2 === null || $f2->status !== 'completed' || $f2->source_cutoff_at === null) {
            throw new RuntimeException('Interest dashboard source is not a completed F2 snapshot.');
        }

        $f5 = SalesforceInterestActivityRun::query()
            ->where('status', 'completed')
            ->where('source_interest_sync_run_id', $f2->id)
            ->where('source_interest_cutoff_at', $f2->source_cutoff_at)
            ->latest('id')->first();
        if ($f5 === null) {
            throw new RuntimeException('Interest dashboard activity snapshot is not aligned with F2.');
        }

        return ['f2' => $f2, 'f5' => $f5];
    }

    private function assertContextStillCurrent(array $context): void
    {
        $current = $this->resolveAlignedContext();
        if ($current['f2']->id !== $context['f2']->id
            || ! $current['f2']->source_cutoff_at->equalTo($context['f2']->source_cutoff_at)
            || $current['f5']->id !== $context['f5']->id) {
            throw new RuntimeException('Interest dashboard sources changed during dataset construction.');
        }
    }

    private function commercialUsers(): Collection
    {
        return $this->interestCommercialUsers ??= SalesforceUser::query()
            ->where('is_active', true)
            ->whereIn('profile_name', self::COMMERCIAL_PROFILES)
            ->get(['salesforce_id', 'name', 'profile_name', 'user_delegation'])
            ->keyBy('salesforce_id');
    }

    private function isTechnicalOwner(?string $ownerId, ?string $ownerName): bool
    {
        return in_array((string) $ownerId, self::TECHNICAL_OWNER_IDS, true)
            || in_array(mb_strtolower(trim((string) $ownerName)), self::TECHNICAL_OWNER_NAMES, true);
    }

    private function passesAccessScopeInterest(array $interest, array $filters): bool
    {
        if (filled($filters['access_commercial'] ?? null)
            && $interest['gestor_id'] !== $filters['access_commercial']) {
            return false;
        }
        if (filled($filters['access_zone'] ?? null)
            && $interest['commercial_zone'] !== $filters['access_zone']) {
            return false;
        }
        if (filled($filters['access_delegation'] ?? null)
            && $interest['commercial_delegation'] !== $filters['access_delegation']
            && $interest['lead_access_delegation'] !== $filters['access_delegation']) {
            return false;
        }

        return true;
    }

    private function cleanValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function auditDateValue(mixed $value): ?string
    {
        return blank($value) ? null : CarbonImmutable::parse($value)->toIso8601String();
    }

    private function interestVocabulary(string $value): string
    {
        return str_ireplace(['leads', 'lead', 'portales', 'portal'], ['intereses', 'Interest', 'fuentes', 'fuente'], $value);
    }
}
