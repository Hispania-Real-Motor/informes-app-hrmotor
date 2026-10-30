<?php

namespace Tests\Unit;

use App\Services\Reports\CallCenterCommissions\Sync\SalesforceTasacionSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceTasacionSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_tasaciones_y_campos_necesarios_para_german(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return [
                    [
                        'Id' => 'a02-1',
                        'Name' => 'Tasacion German',
                        'CreatedDate' => '2026-05-09T10:00:00.000+0000',
                        'Seguimiento__c' => 'German',
                        'Negociaci_n_1__c' => 'Seguimiento inicial',
                        'Negociaci_n_2__c' => 'Llamada 2',
                        'Oportunidad__c' => '006-opp-1',
                        'Oportunidad__r' => [
                            'Name' => 'Opportunity German',
                            'Fecha_firma_contrato__c' => '2026-05-09',
                            'OPO_CAS_Contrato_CV_firmado__c' => true,
                        ],
                    ],
                ];
            }
        };

        $service = new SalesforceTasacionSyncService($client);
        $result = $service->sync(
            CarbonImmutable::parse('2026-05-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'),
        );

        $this->assertSame(1, $result['queried']);
        $this->assertSame(1, $result['saved']);
        $this->assertStringContainsString('FROM Tasacion__c', $result['soql']);
        $this->assertStringContainsString('Oportunidad__r.Fecha_firma_contrato__c', $result['soql']);
        $this->assertStringContainsString('Oportunidad__r.OPO_CAS_Contrato_CV_firmado__c', $result['soql']);
        $this->assertStringContainsString('Negociaci_n_1__c', $result['soql']);
        $this->assertStringContainsString('Negociaci_n_4__c', $result['soql']);
        foreach (["\n    Fecha_firma_contrato__c", "\n    Contrato_CV_firmado__c", 'Negociacion_1__c', 'Opportunity__c', 'RES_BUS_Oportunidad__c', 'TAS_BUS_Oportunidad__c'] as $invalidField) {
            $this->assertStringNotContainsString($invalidField, $result['soql']);
        }
        $this->assertContains('oportunidad_relation', $result['profiles']);
        $this->assertDatabaseHas('salesforce_tasaciones', [
            'salesforce_id' => 'a02-1',
            'opportunity_salesforce_id' => '006-opp-1',
            'opportunity_name' => 'Opportunity German',
            'tracking_name' => 'German',
            'negotiation_1' => 'Seguimiento inicial',
        ]);
    }

    public function test_sync_all_history_arranca_desde_2020(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return [];
            }
        };

        $service = new SalesforceTasacionSyncService($client);
        $service->syncAllHistory(CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'));

        $this->assertNotEmpty($client->queries);
        $this->assertStringContainsString('2020-01-01T00:00:00Z', $client->queries[0]);
    }

    public function test_fallback_without_relation_never_reintroduces_invalid_salesforce_fields(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;
                if (count($this->queries) === 1) {
                    throw new \RuntimeException('Salesforce SOQL: error remoto HTTP 400.');
                }

                return [];
            }
        };

        $service = new SalesforceTasacionSyncService($client);
        $result = $service->sync(CarbonImmutable::parse('2026-05-01', 'UTC'), CarbonImmutable::parse('2026-06-01', 'UTC'));

        $this->assertSame(['without_relation'], $result['profiles']);
        $this->assertCount(2, $client->queries);
        $this->assertStringNotContainsString('Oportunidad__r.', $client->queries[1]);
        $this->assertStringNotContainsString('Negociacion_1__c', $client->queries[1]);
    }
}
