<?php

namespace App\Services\Reports\Calls;

use App\Models\SalesforceCall;
use App\Models\SalesforceCallClassificationHistory;
use App\Models\SalesforceInterest;
use App\Models\SalesforceUser;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SalesforceCallSyncService
{
    private const SYNC_CHUNK_DAYS = 7;

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly CallDescriptionParser $parser,
        private readonly CallPortalNormalizer $portalNormalizer,
        private readonly CallAgentResolver $agentResolver,
        private readonly CallClassificationRules $rules,
        private readonly CallInterestPortalResolver $interestPortalResolver = new CallInterestPortalResolver(
            new CallPortalNormalizer,
        ),
    ) {}

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $this->syncUsers();

        $saved = 0;
        $queried = 0;
        $soqls = [];
        $stats = [
            'answered' => 0,
            'not_answered' => 0,
            'abandoned' => 0,
            'overflows' => 0,
            'inbound' => 0,
            'outbound' => 0,
            'commercial_direct' => 0,
            'portal' => 0,
            'teams' => [],
        ];

        $chunkStart = CarbonImmutable::parse($periodStart);
        $finalEnd = CarbonImmutable::parse($periodEnd);

        while ($chunkStart->lessThan($finalEnd)) {
            $chunkEnd = $chunkStart->addDays(self::SYNC_CHUNK_DAYS)->min($finalEnd);
            $soql = $this->soql($chunkStart, $chunkEnd);
            $records = $this->client->query($soql);
            $soqls[] = $soql;
            $queried += count($records);
            $interestMatches = $this->relatedInterestMatches($records);

            foreach ($records as $record) {
                if (blank(data_get($record, 'Id'))) {
                    continue;
                }

                $call = $this->saveRecord($record, $interestMatches);
                $saved++;
                $this->addStats($stats, $call);
            }

            $chunkStart = $chunkEnd;
        }

        $this->invalidateDashboardCache();

        return [
            'soql' => implode("\n\n-- chunk --\n\n", $soqls),
            'queried' => $queried,
            'saved' => $saved,
            'stats' => $stats,
        ];
    }

    public function syncUsers(): array
    {
        $soql = <<<'SOQL'
SELECT
    Id,
    Name,
    IsActive,
    Profile.Name,
    USR_SEL_Delegacion__c
FROM User
WHERE
    IsActive = true
SOQL;

        $records = $this->client->query($soql);
        $saved = 0;

        foreach ($records as $record) {
            if (blank(data_get($record, 'Id'))) {
                continue;
            }

            SalesforceUser::updateOrCreate(
                ['salesforce_id' => data_get($record, 'Id')],
                [
                    'name' => data_get($record, 'Name'),
                    'profile_name' => data_get($record, 'Profile.Name'),
                    'user_delegation' => data_get($record, 'USR_SEL_Delegacion__c'),
                    'is_active' => (bool) data_get($record, 'IsActive', true),
                    'raw_payload' => $record,
                ]
            );

            $saved++;
        }

        return ['soql' => $soql, 'queried' => count($records), 'saved' => $saved];
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $startDateTime = $this->soqlDateTime($periodStart);
        $endDateTime = $this->soqlDateTime($periodEnd);

        return <<<SOQL
SELECT
    Id,
    Subject,
    Description,
    Type,
    Status,
    Priority,
    ActivityDate,
    CreatedDate,
    LastModifiedDate,
    OwnerId,
    Owner.Name,
    Owner.Profile.Name,
    WhoId,
    WhatId,
    CallObject,
    CallDurationInSeconds,
    CallType,
    Portales__c
FROM Task
WHERE
    IsDeleted = false
    AND Type = 'Call'
    AND CreatedDate >= {$startDateTime}
    AND CreatedDate < {$endDateTime}
ORDER BY CreatedDate DESC
SOQL;
    }

    public function classifyStatus(?string $resultRaw): string
    {
        $result = Str::of((string) $resultRaw)->upper()->trim()->toString();

        return $result === 'ANSWERED' ? 'answered' : 'not_answered';
    }

    public function classifyDirection(?string $callType, ?string $subject, ?string $descriptionType): string
    {
        $haystack = Str::of(trim((string) $callType.' '.(string) $subject.' '.(string) $descriptionType))
            ->lower()
            ->ascii()
            ->toString();

        if (str_contains($haystack, 'inbound') || str_contains($haystack, 'entrante')) {
            return 'inbound';
        }

        if (str_contains($haystack, 'outbound') || str_contains($haystack, 'saliente')) {
            return 'outbound';
        }

        return 'unknown';
    }

    public function adjustedDuration(?int $duration, string $origin): int
    {
        return $this->rules->adjustedDuration($duration, $origin);
    }

    private function saveRecord(array $record, Collection $interestMatches): SalesforceCall
    {
        $existing = SalesforceCall::query()->where('salesforce_id', data_get($record, 'Id'))->first();
        $parsed = $this->parser->parse(data_get($record, 'Description'));
        $existingVisible = $existing === null ? null : [
            'portal' => $existing->portal_resolved,
            'origin' => $existing->call_origin,
            'source' => $existing->portal_resolution_source,
        ];
        $whatId = data_get($record, 'WhatId');
        $portalResolution = $this->interestPortalResolver->resolve(
            data_get($record, 'Portales__c'),
            $whatId,
            $interestMatches->get($whatId),
            $existingVisible,
        );
        $operationalPortal = $portalResolution['operational'];
        $portal = $portalResolution['visible'];
        $duration = $this->duration(data_get($record, 'CallDurationInSeconds'), $parsed['parsed_duration_seconds']);
        $callStatus = $this->classifyStatus($parsed['result_raw'] ?? null);
        $direction = $this->classifyDirection(data_get($record, 'CallType'), data_get($record, 'Subject'), $parsed['type_raw'] ?? null);
        $agent = $this->agentResolver->resolve([
            'id' => data_get($record, 'OwnerId'),
            'name' => data_get($record, 'Owner.Name'),
            'profile_name' => data_get($record, 'Owner.Profile.Name'),
        ], $parsed, $operationalPortal['origin']);
        $normalizedUserKey = $this->agentResolver->normalizedUserKey(
            $agent['operational_user_name'] ?? null,
            $parsed['destination_agent_name'] ?? null,
            data_get($record, 'Owner.Name'),
        );
        $isOverflow = $this->isOverflow(
            $operationalPortal['origin'],
            $callStatus,
            $operationalPortal['portal'],
            $agent['operational_team'] ?? null,
            $parsed['poll_value'] ?? null,
            $parsed['result_raw'] ?? null,
        );
        [$includedInDashboard, $exclusionReason] = $this->dashboardInclusion(
            data_get($record, 'CallObject'),
            $agent['operational_profile_name'] ?? data_get($record, 'Owner.Profile.Name'),
        );

        $call = SalesforceCall::updateOrCreate(
            ['salesforce_id' => data_get($record, 'Id')],
            [
                'subject' => data_get($record, 'Subject'),
                'description' => data_get($record, 'Description'),
                'type' => data_get($record, 'Type'),
                'status' => data_get($record, 'Status'),
                'priority' => data_get($record, 'Priority'),
                'activity_date' => data_get($record, 'ActivityDate'),
                'created_date' => $this->parseDateTime(data_get($record, 'CreatedDate')),
                'last_modified_date' => $this->parseDateTime(data_get($record, 'LastModifiedDate')),
                'owner_id' => data_get($record, 'OwnerId'),
                'owner_name' => data_get($record, 'Owner.Name'),
                'owner_profile_name' => data_get($record, 'Owner.Profile.Name'),
                'who_id' => data_get($record, 'WhoId'),
                'who_type' => $this->whoType(data_get($record, 'WhoId')),
                'what_id' => data_get($record, 'WhatId'),
                'call_object' => data_get($record, 'CallObject'),
                'included_in_dashboard' => $includedInDashboard,
                'dashboard_exclusion_reason' => $exclusionReason,
                'classification_rule_version' => CallClassificationRules::VERSION,
                'classified_at' => now(),
                'call_duration_seconds' => is_numeric(data_get($record, 'CallDurationInSeconds')) ? (int) data_get($record, 'CallDurationInSeconds') : null,
                'parsed_duration_seconds' => $parsed['parsed_duration_seconds'],
                'adjusted_duration_seconds' => $this->adjustedDuration($duration, $operationalPortal['origin']),
                'call_type_raw' => data_get($record, 'CallType'),
                'direction' => $direction,
                'portales_raw' => data_get($record, 'Portales__c'),
                'call_origin' => $operationalPortal['origin'],
                'portal_resolved' => $portal['portal'],
                'portal_resolution_source' => $portal['source'],
                'poll_value' => $parsed['poll_value'],
                'result_raw' => $parsed['result_raw'],
                'call_status' => $callStatus,
                'is_answered' => $callStatus === 'answered',
                'is_lost' => $callStatus !== 'answered',
                'is_overflow' => $isOverflow,
                'overflow_reason' => $this->rules->overflowReason(
                    $operationalPortal['origin'],
                    $callStatus,
                    $operationalPortal['portal'],
                    $agent['operational_team'] ?? null,
                    $parsed['poll_value'] ?? null,
                    $parsed['result_raw'] ?? null,
                ),
                'fixed_phone' => $parsed['fixed_phone'],
                'client_phone' => $parsed['client_phone'],
                'destination_raw' => $parsed['destination_raw'],
                'destination_agent_code' => $parsed['destination_agent_code'],
                'destination_agent_name' => $parsed['destination_agent_name'],
                'operational_user_id' => $agent['operational_user_id'],
                'operational_user_name' => $agent['operational_user_name'],
                'normalized_user_key' => $normalizedUserKey,
                'operational_team' => $agent['operational_team'],
                'owner_team' => $agent['owner_team'],
                'delegation' => $agent['delegation'],
                'zone' => $agent['zone'],
                'queue_raw' => $parsed['queue_raw'],
                'uid_raw' => $parsed['uid_raw'],
                'puid_raw' => $parsed['puid_raw'],
                'call_started_at' => $parsed['call_started_at'],
                'call_ended_at' => $parsed['call_ended_at'],
                'raw_payload' => $record,
                'parse_debug' => [
                    'parsed' => $parsed,
                    'portal_debug' => $portalResolution['debug'],
                ],
            ]
        );

        $classificationChanged = $existing !== null
            && $this->classificationSnapshot($existing) != $this->classificationSnapshot($call);
        if ($classificationChanged) {
            $sourceChanged = (string) $existing->last_modified_date !== (string) $call->last_modified_date;
            SalesforceCallClassificationHistory::query()->create([
                'salesforce_call_id' => $call->id,
                'task_salesforce_id' => $call->salesforce_id,
                'previous_rule_version' => $existing->classification_rule_version,
                'new_rule_version' => CallClassificationRules::VERSION,
                'change_source' => $sourceChanged ? 'salesforce_source_modified' : 'interest_dependency_changed',
                'reason' => $sourceChanged
                    ? 'Salesforce modificó el registro original; se reclasificó durante la sincronización.'
                    : 'La evidencia local exacta de Interest modificó la clasificación funcional.',
                'raw_values' => [
                    'result_raw' => $parsed['result_raw'] ?? null,
                    'answered_by_raw' => $parsed['answered_by_raw'] ?? null,
                    'call_object' => data_get($record, 'CallObject'),
                    'description' => data_get($record, 'Description'),
                    'last_modified_date' => data_get($record, 'LastModifiedDate'),
                ],
                'previous_classification' => $this->classificationSnapshot($existing),
                'new_classification' => $this->classificationSnapshot($call),
                'classified_at' => now(),
            ]);
        }

        return $call;
    }

    private function classificationSnapshot(SalesforceCall $call): array
    {
        return collect([
            'call_status', 'is_answered', 'is_lost', 'is_overflow', 'overflow_reason',
            'call_origin', 'portal_resolved', 'operational_team', 'delegation', 'zone',
            'call_duration_seconds', 'parsed_duration_seconds', 'adjusted_duration_seconds',
            'included_in_dashboard', 'dashboard_exclusion_reason',
        ])->mapWithKeys(fn (string $field): array => [$field => $call->{$field}])->all();
    }

    private function dashboardInclusion(mixed $callObject, ?string $operationalProfile): array
    {
        if (! filled($callObject)) {
            return [false, 'missing_call_object'];
        }

        if ($this->rules->isExcludedTestProfile($operationalProfile)) {
            return [false, CallClassificationRules::EXCLUDED_TEST_PROFILE_REASON];
        }

        return [true, null];
    }

    private function relatedInterestMatches(array $records): Collection
    {
        $ids = collect($records)
            ->pluck('WhatId')
            ->filter(fn ($id) => is_string($id) && preg_match('/^[A-Za-z0-9]{18}$/', $id) === 1)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return SalesforceInterest::query()
            ->whereIn('salesforce_id', $ids)
            ->get(['salesforce_id', 'source', 'is_deleted'])
            ->keyBy('salesforce_id');
    }

    private function duration(mixed $callDuration, ?int $parsedDuration): int
    {
        if (is_numeric($callDuration) && (int) $callDuration >= 0) {
            return (int) $callDuration;
        }

        return max(0, (int) $parsedDuration);
    }

    private function whoType(mixed $whoId): string
    {
        $whoId = (string) $whoId;

        if ($whoId === '') {
            return 'Unknown';
        }

        if (str_starts_with($whoId, '00Q')) {
            return 'Lead';
        }

        if (str_starts_with($whoId, '003')) {
            return 'Contact';
        }

        return 'Other';
    }

    private function addStats(array &$stats, SalesforceCall $call): void
    {
        $stats[$call->is_answered ? 'answered' : 'not_answered']++;
        $stats['abandoned'] += Str::of((string) $call->result_raw)->upper()->trim()->toString() === 'ABANDONED' ? 1 : 0;
        $stats['overflows'] += $call->is_overflow ? 1 : 0;
        $stats[$call->direction] = ($stats[$call->direction] ?? 0) + 1;
        $stats[$call->call_origin] = ($stats[$call->call_origin] ?? 0) + 1;
        $stats['teams'][$call->operational_team] = ($stats['teams'][$call->operational_team] ?? 0) + 1;
    }

    private function isOverflow(
        ?string $origin,
        ?string $status,
        ?string $portal,
        ?string $team,
        ?string $pollValue = null,
        ?string $resultRaw = null,
    ): bool {
        return $this->rules->isOverflow($origin, $status, $portal, $team, $pollValue, $resultRaw);
    }

    private function invalidateDashboardCache(): void
    {
        Cache::forever('salesforce_calls_dashboard_cache_version', ((int) Cache::get('salesforce_calls_dashboard_cache_version', 1)) + 1);
    }

    private function soqlDateTime(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value);
    }
}
