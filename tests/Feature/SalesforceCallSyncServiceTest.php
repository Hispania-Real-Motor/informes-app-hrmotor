<?php

namespace Tests\Feature;

use App\Models\SalesforceCall;
use App\Models\SalesforceCallClassificationHistory;
use App\Models\SalesforceInterest;
use App\Models\SalesforceLead;
use App\Services\Reports\Calls\CallAgentResolver;
use App\Services\Reports\Calls\CallClassificationRules;
use App\Services\Reports\Calls\CallDescriptionParser;
use App\Services\Reports\Calls\CallPortalNormalizer;
use App\Services\Reports\Calls\SalesforceCallSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Database\Seeders\CallAgentMappingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SalesforceCallSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CallAgentMappingsSeeder::class);
    }

    public function test_guarda_task_como_salesforce_call_con_campos_resueltos(): void
    {
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->andReturnUsing(function (string $soql): array {
            if (str_contains($soql, 'FROM User')) {
                return [[
                    'Id' => '005-owner',
                    'Name' => 'Comercial Owner',
                    'IsActive' => true,
                    'Profile' => ['Name' => 'Compra/Venta'],
                    'USR_SEL_Delegacion__c' => 'HR MOTOR ALCOBENDAS',
                ]];
            }

            return [[
                'Id' => '00T-call',
                'Subject' => 'Llamada entrante',
                'Description' => "Resultado: ANSWERED\nTipo: Entrante a fijo\nComercial destino: AG1 - Vanesa Germán\nDuracion de la llamada: 80 segundos",
                'Type' => 'Call',
                'Status' => 'Completed',
                'Priority' => 'Normal',
                'ActivityDate' => '2026-05-10',
                'CreatedDate' => '2026-05-10T10:00:00.000Z',
                'LastModifiedDate' => '2026-05-10T10:05:00.000Z',
                'OwnerId' => '005-owner',
                'Owner' => ['Name' => 'Comercial Owner', 'Profile' => ['Name' => 'Compra/Venta']],
                'WhoId' => '00Q-lead',
                'WhatId' => null,
                'CallObject' => 'call-object-1',
                'CallDurationInSeconds' => 80,
                'CallType' => 'Inbound',
                'Portales__c' => 'Web Alcobendas',
            ]];
        });

        $service = new SalesforceCallSyncService(
            $client,
            app(CallDescriptionParser::class),
            app(CallPortalNormalizer::class),
            app(CallAgentResolver::class),
            app(CallClassificationRules::class),
        );

        $result = $service->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));
        $call = SalesforceCall::first();

        $this->assertSame(1, $result['saved']);
        $this->assertSame('Web', $call->portal_resolved);
        $this->assertSame('portal', $call->call_origin);
        $this->assertSame(CallClassificationRules::VERSION, $call->classification_rule_version);
        $this->assertSame('answered', $call->call_status);
        $this->assertSame('inbound', $call->direction);
        $this->assertSame(70, $call->adjusted_duration_seconds);
        $this->assertSame('contact_center', $call->operational_team);
        $this->assertSame('Alcobendas', $call->delegation);
        $this->assertSame('Zona Sur y Centro', $call->zone);
    }

    public function test_portal_no_clasificado_de_task_usa_interest_local_exacto_sin_consultar_lead(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-lead-conflict',
            'created_date' => '2026-05-10 08:00:00',
            'source_origin_new' => 'Wallapop',
        ]);
        SalesforceInterest::query()->create([
            'salesforce_id' => 'a01AAA000000000AAA',
            'salesforce_created_at' => '2026-05-10 09:00:00',
            'salesforce_last_modified_at' => '2026-05-10 09:00:00',
            'source' => 'Coches.net',
            'is_deleted' => true,
        ]);

        $queries = [];
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->andReturnUsing(function (string $soql) use (&$queries): array {
            $queries[] = $soql;
            if (str_contains($soql, 'FROM User')) {
                return [[
                    'Id' => '005-owner',
                    'Name' => 'Comercial Owner',
                    'IsActive' => true,
                    'Profile' => ['Name' => 'Compra/Venta'],
                    'USR_SEL_Delegacion__c' => 'HR MOTOR ALCOBENDAS',
                ]];
            }

            return [[
                'Id' => '00T-call-conflict',
                'Subject' => 'Llamada entrante',
                'Description' => "Resultado: ANSWERED\nTipo: Entrante a fijo\nComercial destino: AG1 - Vanesa Germán\nDuracion de la llamada: 80 segundos",
                'Type' => 'Call',
                'Status' => 'Completed',
                'Priority' => 'Normal',
                'ActivityDate' => '2026-05-10',
                'CreatedDate' => '2026-05-10T10:00:00.000Z',
                'LastModifiedDate' => '2026-05-10T10:05:00.000Z',
                'OwnerId' => '005-owner',
                'Owner' => ['Name' => 'Comercial Owner', 'Profile' => ['Name' => 'Compra/Venta']],
                'WhoId' => '00Q-lead-conflict',
                'WhatId' => 'a01AAA000000000AAA',
                'CallObject' => 'call-object-conflict',
                'CallDurationInSeconds' => 80,
                'CallType' => 'Inbound',
                'Portales__c' => '3CX',
            ]];
        });

        $service = new SalesforceCallSyncService(
            $client,
            app(CallDescriptionParser::class),
            app(CallPortalNormalizer::class),
            app(CallAgentResolver::class),
            app(CallClassificationRules::class),
        );

        $service->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));

        $call = SalesforceCall::query()->where('salesforce_id', '00T-call-conflict')->firstOrFail();
        $this->assertSame('Coches.net', $call->portal_resolved);
        $this->assertSame('interest', $call->portal_resolution_source);
        $this->assertSame('portal', $call->call_origin);
        $this->assertSame(70, $call->adjusted_duration_seconds);
        $this->assertTrue($call->is_overflow);
        $this->assertSame('exact_interest', data_get($call->parse_debug, 'portal_debug.relationship_status'));
        $this->assertTrue(data_get($call->parse_debug, 'portal_debug.interest_is_deleted'));
        $this->assertTrue(collect($queries)->every(fn (string $query): bool => ! str_contains($query, 'FROM Lead')));
    }

    public function test_interest_change_creates_history_without_task_last_modified_change_and_identical_rerun_does_not(): void
    {
        $interest = SalesforceInterest::query()->create([
            'salesforce_id' => 'a01BBB000000000AAA',
            'salesforce_created_at' => '2026-05-10 09:00:00',
            'salesforce_last_modified_at' => '2026-05-10 09:00:00',
            'source' => 'Coches.net',
            'is_deleted' => false,
        ]);
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->andReturnUsing(fn (string $soql): array => str_contains($soql, 'FROM User') ? [] : [[
            'Id' => '00T-call-history',
            'Type' => 'Call',
            'CreatedDate' => '2026-05-10T10:00:00.000Z',
            'LastModifiedDate' => '2026-05-10T10:05:00.000Z',
            'WhatId' => 'a01BBB000000000AAA',
            'CallObject' => 'call-object-history',
            'Portales__c' => '3CX',
        ]]);
        $service = new SalesforceCallSyncService(
            $client,
            app(CallDescriptionParser::class),
            app(CallPortalNormalizer::class),
            app(CallAgentResolver::class),
            app(CallClassificationRules::class),
        );

        $service->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));
        $service->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));
        $this->assertDatabaseCount('salesforce_call_classification_history', 0);

        $interest->update(['source' => 'Wallapop']);
        $service->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));

        $history = SalesforceCallClassificationHistory::query()->sole();
        $this->assertSame('interest_dependency_changed', $history->change_source);
        $this->assertSame('Coches.net', data_get($history->raw_values, 'interest_dependency.previous.interest_source_raw'));
        $this->assertSame('Wallapop', data_get($history->raw_values, 'interest_dependency.current.interest_source_raw'));
        $this->assertTrue(data_get($history->raw_values, 'interest_dependency.current.interest_source_used'));
        $this->assertSame('Wallapop', SalesforceCall::query()->where('salesforce_id', '00T-call-history')->value('portal_resolved'));
    }

    public function test_local_user_dependency_change_uses_neutral_history_provenance(): void
    {
        $delegation = 'HR MOTOR ALCOBENDAS';
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->andReturnUsing(function (string $soql) use (&$delegation): array {
            if (str_contains($soql, 'FROM User')) {
                return [[
                    'Id' => '005-local-owner',
                    'Name' => 'Comercial Local',
                    'IsActive' => true,
                    'Profile' => ['Name' => 'Compra/Venta'],
                    'USR_SEL_Delegacion__c' => $delegation,
                ]];
            }

            return [[
                'Id' => '00T-local-change',
                'Type' => 'Call',
                'CreatedDate' => '2026-05-10T10:00:00.000Z',
                'LastModifiedDate' => '2026-05-10T10:05:00.000Z',
                'OwnerId' => '005-local-owner',
                'Owner' => ['Name' => 'Comercial Local', 'Profile' => ['Name' => 'Compra/Venta']],
                'WhatId' => null,
                'CallObject' => 'call-object-local-change',
                'Portales__c' => 'Web',
            ]];
        });

        $this->service($client)->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));
        $delegation = 'HR MOTOR PAMPLONA';
        $this->service($client)->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));

        $history = SalesforceCallClassificationHistory::query()->sole();
        $this->assertSame('local_classification_changed', $history->change_source);
        $this->assertStringContainsString('no atribuible a Interest', $history->reason);
        $this->assertSame('Pamplona', SalesforceCall::query()->where('salesforce_id', '00T-local-change')->value('delegation'));
    }

    public function test_historical_preservation_does_not_claim_interest_causality_for_local_change(): void
    {
        SalesforceCall::query()->create([
            'salesforce_id' => '00T-preserved-local-change',
            'created_date' => '2026-05-10 10:00:00',
            'last_modified_date' => '2026-05-10 10:05:00',
            'what_id' => 'a01ZZZ000000000AAA',
            'call_object' => 'call-object-preserved',
            'portales_raw' => '3CX',
            'call_origin' => 'portal',
            'portal_resolved' => 'Coches.net',
            'portal_resolution_source' => 'interest',
            'operational_team' => 'commercial',
            'delegation' => 'Alcobendas',
            'zone' => 'Zona Sur y Centro',
            'classification_rule_version' => CallClassificationRules::VERSION,
        ]);
        $delegation = 'HR MOTOR ALCOBENDAS';
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->andReturnUsing(function (string $soql) use (&$delegation): array {
            if (str_contains($soql, 'FROM User')) {
                return [[
                    'Id' => '005-preserved-owner',
                    'Name' => 'Comercial Preservado',
                    'IsActive' => true,
                    'Profile' => ['Name' => 'Compra/Venta'],
                    'USR_SEL_Delegacion__c' => $delegation,
                ]];
            }

            return [[
                'Id' => '00T-preserved-local-change',
                'Type' => 'Call',
                'CreatedDate' => '2026-05-10T10:00:00.000Z',
                'LastModifiedDate' => '2026-05-10T10:05:00.000Z',
                'OwnerId' => '005-preserved-owner',
                'Owner' => ['Name' => 'Comercial Preservado', 'Profile' => ['Name' => 'Compra/Venta']],
                'WhatId' => 'a01ZZZ000000000AAA',
                'CallObject' => 'call-object-preserved',
                'Portales__c' => '3CX',
            ]];
        });

        $this->service($client)->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));
        SalesforceCallClassificationHistory::query()->delete();
        $delegation = 'HR MOTOR PAMPLONA';
        $this->service($client)->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));

        $call = SalesforceCall::query()->where('salesforce_id', '00T-preserved-local-change')->firstOrFail();
        $history = SalesforceCallClassificationHistory::query()->sole();
        $this->assertSame('local_classification_changed', $history->change_source);
        $this->assertTrue(data_get($history->raw_values, 'interest_dependency.previous.preserved_historical'));
        $this->assertTrue(data_get($history->raw_values, 'interest_dependency.current.preserved_historical'));
        $this->assertSame('interest_not_local', data_get($call->parse_debug, 'portal_debug.relationship_status'));
        $this->assertFalse(data_get($call->parse_debug, 'portal_debug.interest_matched'));
        $this->assertSame('Coches.net', $call->portal_resolved);
        $this->assertSame('historical_preserved', $call->portal_resolution_source);
        $this->assertSame('Pamplona', $call->delegation);
    }

    public function test_historical_to_exact_same_portal_with_local_change_remains_local_provenance(): void
    {
        SalesforceCall::query()->create([
            'salesforce_id' => '00T-preserved-to-exact',
            'created_date' => '2026-05-10 10:00:00',
            'last_modified_date' => '2026-05-10 10:05:00',
            'what_id' => 'a01YYY000000000AAA',
            'call_object' => 'call-object-preserved-to-exact',
            'portales_raw' => '3CX',
            'call_origin' => 'portal',
            'portal_resolved' => 'Coches.net',
            'portal_resolution_source' => 'interest',
            'operational_team' => 'commercial',
            'delegation' => 'Alcobendas',
            'zone' => 'Zona Sur y Centro',
            'classification_rule_version' => CallClassificationRules::VERSION,
        ]);
        $delegation = 'HR MOTOR ALCOBENDAS';
        $client = $this->clientForStableTaskWithOwner(
            '00T-preserved-to-exact',
            'a01YYY000000000AAA',
            $delegation,
        );

        $this->service($client)->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));
        SalesforceCallClassificationHistory::query()->delete();
        SalesforceInterest::query()->create([
            'salesforce_id' => 'a01YYY000000000AAA',
            'salesforce_created_at' => '2026-05-10 09:00:00',
            'salesforce_last_modified_at' => '2026-05-10 09:00:00',
            'source' => 'Coches.net',
            'is_deleted' => false,
        ]);
        $delegation = 'HR MOTOR PAMPLONA';
        $client = $this->clientForStableTaskWithOwner(
            '00T-preserved-to-exact',
            'a01YYY000000000AAA',
            $delegation,
        );
        $this->service($client)->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));

        $call = SalesforceCall::query()->where('salesforce_id', '00T-preserved-to-exact')->firstOrFail();
        $history = SalesforceCallClassificationHistory::query()->sole();
        $this->assertSame('local_classification_changed', $history->change_source);
        $this->assertTrue(data_get($history->raw_values, 'interest_dependency.previous.preserved_historical'));
        $this->assertSame('exact_interest', data_get($history->raw_values, 'interest_dependency.current.relationship_status'));
        $this->assertTrue(data_get($history->raw_values, 'interest_dependency.current.interest_source_used'));
        $this->assertSame('Coches.net', data_get($history->previous_classification, 'portal_resolved'));
        $this->assertSame('Coches.net', data_get($history->new_classification, 'portal_resolved'));
        $this->assertSame('Coches.net', $call->portal_resolved);
        $this->assertSame('Pamplona', $call->delegation);
    }

    public function test_different_interest_sources_with_same_normalized_portal_do_not_claim_causality(): void
    {
        $interest = SalesforceInterest::query()->create([
            'salesforce_id' => 'a01XXX000000000AAA',
            'salesforce_created_at' => '2026-05-10 09:00:00',
            'salesforce_last_modified_at' => '2026-05-10 09:00:00',
            'source' => 'Web Alcobendas',
            'is_deleted' => false,
        ]);
        $delegation = 'HR MOTOR ALCOBENDAS';
        $client = $this->clientForStableTaskWithOwner(
            '00T-same-normalized-portal',
            'a01XXX000000000AAA',
            $delegation,
        );
        $this->service($client)->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));

        $interest->update(['source' => 'Web Pamplona']);
        $delegation = 'HR MOTOR PAMPLONA';
        $client = $this->clientForStableTaskWithOwner(
            '00T-same-normalized-portal',
            'a01XXX000000000AAA',
            $delegation,
        );
        $this->service($client)->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));

        $history = SalesforceCallClassificationHistory::query()->sole();
        $this->assertSame('local_classification_changed', $history->change_source);
        $this->assertSame('Web Alcobendas', data_get($history->raw_values, 'interest_dependency.previous.interest_source_raw'));
        $this->assertSame('Web Pamplona', data_get($history->raw_values, 'interest_dependency.current.interest_source_raw'));
        $this->assertSame('Web', data_get($history->previous_classification, 'portal_resolved'));
        $this->assertSame('Web', data_get($history->new_classification, 'portal_resolved'));
    }

    public function test_task_last_modified_change_keeps_salesforce_history_provenance(): void
    {
        $taskState = ['last_modified' => '2026-05-10T10:05:00.000Z', 'portal' => 'Web'];
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->andReturnUsing(function (string $soql) use (&$taskState): array {
            if (str_contains($soql, 'FROM User')) {
                return [];
            }

            return [[
                'Id' => '00T-salesforce-change',
                'Type' => 'Call',
                'CreatedDate' => '2026-05-10T10:00:00.000Z',
                'LastModifiedDate' => $taskState['last_modified'],
                'WhatId' => null,
                'CallObject' => 'call-object-salesforce-change',
                'Portales__c' => $taskState['portal'],
            ]];
        });

        $this->service($client)->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));
        $taskState = ['last_modified' => '2026-05-10T11:05:00.000Z', 'portal' => 'Wallapop'];
        $this->service($client)->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));

        $history = SalesforceCallClassificationHistory::query()->sole();
        $this->assertSame('salesforce_source_modified', $history->change_source);
        $this->assertSame('Wallapop', SalesforceCall::query()->where('salesforce_id', '00T-salesforce-change')->value('portal_resolved'));
    }

    public function test_lead_who_id_cannot_supply_fallback_without_exact_interest_what_id(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00QAAA000000000AAA',
            'created_date' => '2026-05-10 08:00:00',
            'source_origin_new' => 'Coches.net',
        ]);
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->andReturnUsing(fn (string $soql): array => str_contains($soql, 'FROM User') ? [] : [[
            'Id' => '00T-no-interest',
            'Type' => 'Call',
            'CreatedDate' => '2026-05-10T10:00:00.000Z',
            'LastModifiedDate' => '2026-05-10T10:05:00.000Z',
            'WhoId' => '00QAAA000000000AAA',
            'WhatId' => null,
            'CallObject' => 'call-object-no-interest',
            'Portales__c' => '3CX',
        ]]);

        $this->service($client)->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));

        $call = SalesforceCall::query()->where('salesforce_id', '00T-no-interest')->firstOrFail();
        $this->assertSame('00QAAA000000000AAA', $call->who_id);
        $this->assertSame('Sin clasificar', $call->portal_resolved);
        $this->assertSame('unclassified', $call->portal_resolution_source);
        $this->assertSame('no_reference', data_get($call->parse_debug, 'portal_debug.relationship_status'));
    }

    public function test_resolves_multiple_interests_with_one_local_batch_query_and_no_salesforce_crm_query(): void
    {
        foreach ([
            ['a01CCC000000000AAA', 'Coches.net'],
            ['a01DDD000000000AAA', 'Wallapop'],
        ] as [$id, $source]) {
            SalesforceInterest::query()->create([
                'salesforce_id' => $id,
                'salesforce_created_at' => '2026-05-10 09:00:00',
                'salesforce_last_modified_at' => '2026-05-10 09:00:00',
                'source' => $source,
                'is_deleted' => false,
            ]);
        }

        $remoteQueries = [];
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->andReturnUsing(function (string $soql) use (&$remoteQueries): array {
            $remoteQueries[] = $soql;

            if (str_contains($soql, 'FROM User')) {
                return [];
            }

            return collect(['a01CCC000000000AAA', 'a01DDD000000000AAA'])
                ->map(fn (string $interestId, int $index): array => [
                    'Id' => '00T-batch-'.$index,
                    'Type' => 'Call',
                    'CreatedDate' => '2026-05-10T10:00:00.000Z',
                    'LastModifiedDate' => '2026-05-10T10:05:00.000Z',
                    'WhatId' => $interestId,
                    'CallObject' => 'call-object-'.$index,
                    'Portales__c' => '3CX',
                ])->all();
        });
        $interestSelects = 0;
        DB::listen(function ($query) use (&$interestSelects): void {
            if (str_contains($query->sql, 'from "salesforce_interests"')) {
                $interestSelects++;
            }
        });

        $this->service($client)->sync(CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-11'));

        $this->assertSame(1, $interestSelects);
        $this->assertSame(['Coches.net', 'Wallapop'], SalesforceCall::query()->orderBy('salesforce_id')->pluck('portal_resolved')->all());
        $this->assertTrue(collect($remoteQueries)->every(
            fn (string $query): bool => ! str_contains($query, 'FROM Lead') && ! str_contains($query, 'FROM Interes__c')
        ));
    }

    private function service(SalesforceClient $client): SalesforceCallSyncService
    {
        return new SalesforceCallSyncService(
            $client,
            app(CallDescriptionParser::class),
            app(CallPortalNormalizer::class),
            app(CallAgentResolver::class),
            app(CallClassificationRules::class),
        );
    }

    private function clientForStableTaskWithOwner(
        string $taskId,
        string $interestId,
        string $delegation,
    ): SalesforceClient {
        $client = Mockery::mock(SalesforceClient::class);
        $client->shouldReceive('query')->andReturnUsing(function (string $soql) use ($taskId, $interestId, $delegation): array {
            if (str_contains($soql, 'FROM User')) {
                return [[
                    'Id' => '005-stable-owner',
                    'Name' => 'Comercial Estable',
                    'IsActive' => true,
                    'Profile' => ['Name' => 'Compra/Venta'],
                    'USR_SEL_Delegacion__c' => $delegation,
                ]];
            }

            return [[
                'Id' => $taskId,
                'Type' => 'Call',
                'CreatedDate' => '2026-05-10T10:00:00.000Z',
                'LastModifiedDate' => '2026-05-10T10:05:00.000Z',
                'OwnerId' => '005-stable-owner',
                'Owner' => ['Name' => 'Comercial Estable', 'Profile' => ['Name' => 'Compra/Venta']],
                'WhatId' => $interestId,
                'CallObject' => 'call-object-stable',
                'Portales__c' => '3CX',
            ]];
        });

        return $client;
    }
}
