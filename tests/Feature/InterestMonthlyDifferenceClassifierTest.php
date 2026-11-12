<?php

namespace Tests\Feature;

use App\Services\Salesforce\InterestMonthlyDifferenceClassifier;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class InterestMonthlyDifferenceClassifierTest extends TestCase
{
    public function test_group_fingerprints_distinguish_changes_without_persisting_values(): void
    {
        $mapped = app(SalesforceInterestSyncService::class)->mapRecord([
            'Id' => 'a01000000000000001', 'CreatedDate' => '2026-11-05T10:00:00Z',
            'LastModifiedDate' => '2026-11-05T10:00:00Z', 'SystemModstamp' => '2026-11-05T10:00:00Z',
            'IsDeleted' => false, 'IN_Estado__c' => 'Private synthetic status',
            'IN_Lead__c' => '00Q000000000000001', 'OwnerId' => '005000000000000001',
            'Owner' => ['Name' => 'Private synthetic owner'],
        ], false);
        $classifier = app(InterestMonthlyDifferenceClassifier::class);
        $stored = json_encode($classifier->fingerprints($mapped), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private synthetic', $stored);
        $this->assertStringNotContainsString('00Q000000000000001', $stored);
        $this->assertSame(InterestMonthlyDifferenceClassifier::UNKNOWN, $classifier->changedMask(null, $classifier->fingerprints($mapped)));

        foreach ([
            ['status', 'Changed synthetic status', 1],
            ['lead_salesforce_id', '00Q000000000000002', 2],
            ['owner_salesforce_id', '005000000000000002', 4],
            ['owner_name', 'Changed synthetic owner', 8],
            ['system_modstamp_at', CarbonImmutable::parse('2026-11-12T08:36:13Z'), 16],
            ['salesforce_last_modified_at', CarbonImmutable::parse('2026-11-12T08:36:13Z'), 512],
            ['origin_created_at', CarbonImmutable::parse('2026-11-06T10:00:00Z'), 32],
            ['is_deleted', true, 64],
            ['canonical_person_type', 'Account', 128],
        ] as [$field, $value, $expected]) {
            $changed = $mapped;
            $changed[$field] = $value;
            $this->assertSame($expected, $classifier->changedMask($stored, $classifier->fingerprints($changed)), $field);
        }

        $changed = $mapped;
        $changed['synced_at'] = CarbonImmutable::parse('2026-11-12T10:00:00Z');
        $this->assertSame(InterestMonthlyDifferenceClassifier::UNKNOWN, $classifier->changedMask($stored, $classifier->fingerprints($changed)));
    }

    public function test_missing_or_incompatible_fingerprints_remain_unclassified(): void
    {
        $classifier = app(InterestMonthlyDifferenceClassifier::class);
        $current = $classifier->fingerprints(['status' => 'Synthetic']);
        $this->assertSame(InterestMonthlyDifferenceClassifier::UNKNOWN, $classifier->changedMask('{"version":2}', $current));
        $this->assertSame(InterestMonthlyDifferenceClassifier::UNKNOWN, $classifier->changedMask('{', $current));
        $oldKey = $current;
        $oldKey['key_id'] = 'different-key-id';
        $this->assertSame(InterestMonthlyDifferenceClassifier::UNKNOWN,
            $classifier->changedMask(json_encode($oldKey, JSON_THROW_ON_ERROR), $current));
    }
}
