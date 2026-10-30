<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use App\Models\CommercialDelegationSnapshot;
use App\Models\ContactCenterCommissionRosterMember;
use App\Models\SalesforceUser;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SalesforceMonthlyUsersSyncService
{
    private const COMMERCIAL_PROFILES = [
        'Compra/Venta',
        'Comerciales Partner Community',
    ];

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly ChangedRowUpsert $changedRowUpsert = new ChangedRowUpsert,
    ) {}

    public function sync(): array
    {
        $soql = $this->soql();
        $records = collect($this->client->query($soql))->keyBy(fn (array $record): string => (string) data_get($record, 'Id'));
        $trackedIds = $this->trackedUserIds()->diff($records->keys())->values();
        $trackedRefreshQueries = 0;

        foreach ($trackedIds->chunk(100) as $ids) {
            $trackedRefreshQueries++;
            foreach ($this->client->query($this->trackedUsersSoql($ids)) as $record) {
                if (filled(data_get($record, 'Id'))) {
                    $records->put((string) data_get($record, 'Id'), $record);
                }
            }
        }

        $values = [];

        foreach ($records as $record) {
            if (blank(data_get($record, 'Id'))) {
                continue;
            }

            $values[] = [
                'salesforce_id' => data_get($record, 'Id'),
                'name' => data_get($record, 'Name'),
                'email' => data_get($record, 'Email'),
                'created_date' => $this->parseDateTime(data_get($record, 'CreatedDate')),
                'profile_name' => data_get($record, 'Profile.Name'),
                'user_delegation' => data_get($record, 'USR_SEL_Delegacion__c'),
                'is_active' => (bool) data_get($record, 'IsActive', true),
                'commission_appraiser' => (bool) data_get($record, 'Comision_Tasador__c', false),
                'raw_payload' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach (array_chunk($values, 500) as $chunk) {
            $chunkStats = $this->changedRowUpsert->persist(SalesforceUser::class, $chunk, 'salesforce_id');
            foreach ($stats as $key => $value) {
                $stats[$key] += $chunkStats[$key];
            }
        }

        return [
            'soql' => $soql,
            'queried' => count($records),
            'saved' => count($values),
            'tracked_refresh_queries' => $trackedRefreshQueries,
            ...$stats,
        ];
    }

    private function trackedUserIds(): Collection
    {
        return SalesforceUser::query()
            ->where(function ($query): void {
                $query->whereIn('profile_name', self::COMMERCIAL_PROFILES)
                    ->orWhere('commission_appraiser', true)
                    ->orWhereIn('salesforce_id', ContactCenterCommissionRosterMember::query()->select('salesforce_user_id'))
                    ->orWhereIn('salesforce_id', CommercialDelegationSnapshot::query()
                        ->whereNull('observed_until')
                        ->select('salesforce_user_id'));
            })
            ->pluck('salesforce_id');
    }

    private function trackedUsersSoql(Collection $ids): string
    {
        $quotedIds = $ids
            ->map(fn (string $id): string => "'".str_replace("'", "\\'", $id)."'")
            ->implode(', ');

        $soql = <<<'SOQL'
SELECT
    Id,
    Name,
    Email,
    CreatedDate,
    Profile.Name,
    USR_SEL_Delegacion__c,
    Comision_Tasador__c,
    IsActive
FROM User
WHERE Id IN (__USER_IDS__)
SOQL;

        return str_replace('__USER_IDS__', $quotedIds, $soql);
    }

    public function soql(): string
    {
        return <<<'SOQL'
SELECT
    Id,
    Name,
    Email,
    CreatedDate,
    Profile.Name,
    USR_SEL_Delegacion__c,
    Comision_Tasador__c,
    IsActive
FROM User
WHERE
    (
        Profile.Name = 'Comerciales Partner Community'
        OR Profile.Name = 'Compra/Venta'
        OR Comision_Tasador__c = true
    )
SOQL;
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value);
    }
}
