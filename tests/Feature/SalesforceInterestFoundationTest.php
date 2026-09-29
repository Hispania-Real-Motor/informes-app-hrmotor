<?php

namespace Tests\Feature;

use App\Models\SalesforceInterest;
use App\Services\Salesforce\SalesforceInterestFoundationResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesforceInterestFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_is_additive_queryable_and_does_not_store_contact_pii(): void
    {
        $this->assertTrue(Schema::hasColumns('salesforce_interests', [
            'id',
            'salesforce_id',
            'lead_salesforce_id',
            'account_salesforce_id',
            'migration_origin_lead_id',
            'salesforce_created_at',
            'salesforce_last_modified_at',
            'origin_created_at',
            'functional_created_at',
            'canonical_person_type',
            'canonical_person_salesforce_id',
            'inverse_opportunity_salesforce_id',
            'raw_payload',
            'synced_at',
        ]));
        $this->assertFalse(Schema::hasColumn('salesforce_interests', 'phone'));
        $this->assertFalse(Schema::hasColumn('salesforce_interests', 'email'));
        $this->assertFalse(Schema::hasColumn('salesforce_interests', 'person_name'));
    }

    public function test_person_and_functional_date_are_materialized_with_confirmed_precedence(): void
    {
        $leadOnly = $this->interest('a01000000000000001', [
            'lead_salesforce_id' => '00Q000000000000001',
            'origin_created_at' => '2024-01-10 08:30:00',
        ]);
        $accountOnly = $this->interest('a01000000000000002', [
            'account_salesforce_id' => '001000000000000002',
        ]);
        $both = $this->interest('a01000000000000003', [
            'lead_salesforce_id' => '00Q000000000000003',
            'account_salesforce_id' => '001000000000000003',
        ]);
        $neither = $this->interest('a01000000000000004');

        $this->assertSame('Lead', $leadOnly->canonical_person_type);
        $this->assertSame('00Q000000000000001', $leadOnly->canonical_person_salesforce_id);
        $this->assertSame('2024-01-10 08:30:00', $leadOnly->functional_created_at->format('Y-m-d H:i:s'));
        $this->assertSame('Account', $accountOnly->canonical_person_type);
        $this->assertSame('001000000000000002', $accountOnly->canonical_person_salesforce_id);
        $this->assertSame('2024-02-01 10:00:00', $accountOnly->functional_created_at->format('Y-m-d H:i:s'));
        $this->assertSame('Account', $both->canonical_person_type);
        $this->assertSame('001000000000000003', $both->canonical_person_salesforce_id);
        $this->assertNull($neither->canonical_person_type);
        $this->assertNull($neither->canonical_person_salesforce_id);
        $this->assertSame('2024-02-01 10:00:00', $neither->functional_created_at->format('Y-m-d H:i:s'));
        $this->assertNotSame($neither->created_at->format('Y-m-d H:i:s'), $neither->functional_created_at->format('Y-m-d H:i:s'));
    }

    public function test_multiple_interests_can_share_a_person_and_null_migration_origin(): void
    {
        $first = $this->interest('a01000000000000005', ['account_salesforce_id' => '001000000000000005']);
        $second = $this->interest('a01000000000000006', ['account_salesforce_id' => '001000000000000005']);

        $this->assertNull($first->migration_origin_lead_id);
        $this->assertNull($second->migration_origin_lead_id);
        $this->assertSame($first->canonical_person_salesforce_id, $second->canonical_person_salesforce_id);
        $this->assertDatabaseCount('salesforce_interests', 2);
    }

    public function test_unique_salesforce_interest_id_is_enforced(): void
    {
        $this->interest('a01000000000000007');

        $this->expectException(QueryException::class);
        $this->interest('a01000000000000007');
    }

    public function test_non_null_migration_origin_lead_is_unique(): void
    {
        $this->interest('a01000000000000008', ['migration_origin_lead_id' => '00Q000000000000008']);

        $this->expectException(QueryException::class);
        $this->interest('a01000000000000009', ['migration_origin_lead_id' => '00Q000000000000008']);
    }

    public function test_unknown_values_and_confirmed_types_are_preserved_without_normalization(): void
    {
        foreach (['Venta', 'Venta con cambio', 'Tasación', 'Tipo futuro'] as $index => $type) {
            $interest = $this->interest('a01'.str_pad((string) (10 + $index), 15, '0', STR_PAD_LEFT), [
                'status' => 'Estado futuro',
                'type' => $type,
                'medium' => 'Organic',
                'channel' => 'Whatsapp',
            ]);

            $this->assertSame('Estado futuro', $interest->status);
            $this->assertSame($type, $interest->type);
            $this->assertSame('Organic', $interest->medium);
            $this->assertSame('Whatsapp', $interest->channel);
        }
    }

    public function test_both_vehicles_long_utm_term_and_nullable_opportunity_are_supported(): void
    {
        $term = str_repeat('t', 255);
        $interest = $this->interest('a01000000000000014', [
            'sale_vehicle_salesforce_id' => '01t000000000000014',
            'appraisal_vehicle_salesforce_id' => '01t000000000000015',
            'utm_term' => $term,
        ]);

        $this->assertSame('01t000000000000014', $interest->sale_vehicle_salesforce_id);
        $this->assertSame('01t000000000000015', $interest->appraisal_vehicle_salesforce_id);
        $this->assertSame(255, strlen($interest->utm_term));
        $this->assertNull($interest->inverse_opportunity_salesforce_id);
    }

    public function test_canonical_person_is_recomputed_when_account_is_added(): void
    {
        $interest = $this->interest('a01000000000000015', [
            'lead_salesforce_id' => '00Q000000000000015',
        ]);

        $interest->update(['account_salesforce_id' => '001000000000000015']);
        $interest->refresh();

        $this->assertSame('Account', $interest->canonical_person_type);
        $this->assertSame('001000000000000015', $interest->canonical_person_salesforce_id);
    }

    public function test_pure_resolver_returns_null_without_any_salesforce_date(): void
    {
        $resolver = new SalesforceInterestFoundationResolver;

        $this->assertNull($resolver->functionalCreatedAt(null, null));
        $this->assertNull($resolver->functionalCreatedAt('', '   '));
        $this->assertSame(
            ['type' => null, 'salesforce_id' => null],
            $resolver->canonicalPerson(' ', null),
        );
    }

    public function test_functional_date_uses_the_first_present_salesforce_date(): void
    {
        $resolver = new SalesforceInterestFoundationResolver;
        $createdDate = '2024-02-01 10:00:00';

        foreach ([null, '', '   '] as $missingOrigin) {
            $this->assertSame(
                $createdDate,
                $resolver->functionalCreatedAt($missingOrigin, $createdDate)?->format('Y-m-d H:i:s'),
            );
        }

        $this->assertSame(
            '2024-01-10 08:30:00',
            $resolver->functionalCreatedAt('2024-01-10 08:30:00', $createdDate)?->format('Y-m-d H:i:s'),
        );
    }

    public function test_removing_origin_date_recomputes_the_eloquent_fallback(): void
    {
        $interest = $this->interest('a01000000000000016', [
            'origin_created_at' => '2024-01-10 08:30:00',
        ]);

        $interest->update(['origin_created_at' => null]);
        $interest->refresh();

        $this->assertNull($interest->origin_created_at);
        $this->assertSame('2024-02-01 10:00:00', $interest->functional_created_at->format('Y-m-d H:i:s'));
    }

    public function test_bulk_rows_can_be_materialized_explicitly_without_model_events(): void
    {
        $resolver = new SalesforceInterestFoundationResolver;

        $row = $resolver->materialize([
            'salesforce_id' => 'a01000000000000017',
            'lead_salesforce_id' => '00Q000000000000017',
            'account_salesforce_id' => '001000000000000017',
            'origin_created_at' => ' ',
            'salesforce_created_at' => '2024-02-01 10:00:00',
        ]);

        $this->assertSame('Account', $row['canonical_person_type']);
        $this->assertSame('001000000000000017', $row['canonical_person_salesforce_id']);
        $this->assertSame('2024-02-01 10:00:00', $row['functional_created_at']->format('Y-m-d H:i:s'));
    }

    private function interest(string $salesforceId, array $attributes = []): SalesforceInterest
    {
        return SalesforceInterest::query()->create(array_merge([
            'salesforce_id' => $salesforceId,
            'salesforce_created_at' => '2024-02-01 10:00:00',
            'salesforce_last_modified_at' => '2024-02-02 11:00:00',
        ], $attributes));
    }
}
