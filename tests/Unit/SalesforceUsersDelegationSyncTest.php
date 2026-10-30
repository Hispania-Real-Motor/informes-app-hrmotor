<?php

namespace Tests\Unit;

use App\Models\CommercialDelegationSnapshot;
use App\Models\ContactCenterCommissionRosterMember;
use App\Models\SalesforceUser;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyUsersSyncService;
use App\Services\Reports\ReservationsSales\CommercialDelegationSnapshotService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceUsersDelegationSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_delegacion_de_usuario_salesforce(): void
    {
        $client = new class extends SalesforceClient
        {
            public string $soql = '';

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->soql = $soql;

                return [[
                    'Id' => '005-commercial',
                    'Name' => 'Comercial Madrid',
                    'Email' => 'comercial.madrid@hrmotor.com',
                    'CreatedDate' => '2026-04-10T14:11:59.000+0000',
                    'Profile' => ['Name' => 'Compra/Venta'],
                    'USR_SEL_Delegacion__c' => 'HR MOTOR MADRID',
                    'Comision_Tasador__c' => true,
                    'IsActive' => true,
                ]];
            }
        };

        $result = (new SalesforceMonthlyUsersSyncService($client))->sync();

        $this->assertSame(1, $result['saved']);
        $this->assertDatabaseHas('salesforce_users', [
            'salesforce_id' => '005-commercial',
            'user_delegation' => 'HR MOTOR MADRID',
        ]);
        $user = SalesforceUser::firstOrFail();

        $this->assertSame('HR MOTOR MADRID', $user->user_delegation);
        $this->assertSame('comercial.madrid@hrmotor.com', $user->email);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->commission_appraiser);
        $this->assertInstanceOf(CarbonInterface::class, $user->created_date);
        $this->assertSame('2026-04-10 14:11:59', $user->created_date->utc()->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('CreatedDate', $client->soql);
    }

    public function test_no_reescribe_usuario_identico_y_actualiza_un_cambio_real(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $record = [
                'Id' => '005-stable',
                'Name' => 'Comercial estable',
                'Email' => 'stable@example.test',
                'Profile' => ['Name' => 'Compra/Venta'],
                'USR_SEL_Delegacion__c' => 'MADRID',
                'Comision_Tasador__c' => false,
                'IsActive' => true,
                'CreatedDate' => '2026-04-10T14:11:59.000+0000',
                'attributes' => ['type' => 'User', 'url' => '/users/005-stable'],
                'OptionalNull' => null,
            ];

            public function __construct() {}

            public function query(string $soql): array
            {
                return [$this->record];
            }
        };

        $service = new SalesforceMonthlyUsersSyncService($client);
        CarbonImmutable::setTestNow('2026-05-03 10:00:00');
        $first = $service->sync();
        $originalUpdatedAt = SalesforceUser::where('salesforce_id', '005-stable')->value('updated_at');

        CarbonImmutable::setTestNow('2026-05-03 10:15:00');
        $client->record['attributes'] = ['url' => '/users/005-stable', 'type' => 'User'];
        $client->record['OptionalNull'] = '';
        $second = $service->sync();

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(0, $second['updated']);
        $this->assertEquals($originalUpdatedAt, SalesforceUser::where('salesforce_id', '005-stable')->value('updated_at'));

        $client->record['USR_SEL_Delegacion__c'] = 'ALCOBENDAS';
        CarbonImmutable::setTestNow('2026-05-03 10:30:00');
        $third = $service->sync();

        $this->assertSame(1, $third['updated']);
        $this->assertSame('ALCOBENDAS', SalesforceUser::where('salesforce_id', '005-stable')->value('user_delegation'));
        $this->assertNotEquals($originalUpdatedAt, SalesforceUser::where('salesforce_id', '005-stable')->value('updated_at'));
    }

    public function test_persiste_inactivos_para_historial_sin_ocultar_su_estado(): void
    {
        $client = new class extends SalesforceClient
        {
            public string $soql = '';

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->soql = $soql;

                return [[
                    'Id' => '005-inactive-history', 'Name' => 'Histórico', 'Email' => null,
                    'Profile' => ['Name' => 'Compra/Venta'], 'USR_SEL_Delegacion__c' => 'HR MOTOR ALICANTE',
                    'Comision_Tasador__c' => false, 'IsActive' => false,
                ]];
            }
        };

        (new SalesforceMonthlyUsersSyncService($client))->sync();

        $this->assertDatabaseHas('salesforce_users', [
            'salesforce_id' => '005-inactive-history',
            'is_active' => false,
        ]);
        $this->assertNull(SalesforceUser::query()->firstOrFail()->created_date);
        $this->assertStringNotContainsString('IsActive = true', $client->soql);
    }

    public function test_refresca_usuario_conocido_fuera_del_filtro_y_cierra_snapshot_sin_falsear_is_active(): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => '005-profile-change', 'name' => 'Cambio perfil',
            'profile_name' => 'Compra/Venta', 'user_delegation' => 'HR MOTOR ALICANTE',
            'is_active' => true, 'commission_appraiser' => false,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-profile-change', 'delegation' => 'Alicante',
            'zone' => 'Zona Mediterraneo', 'observed_from' => '2026-08-01 00:00:00', 'source' => 'test',
        ]);
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;
                if (! str_contains($soql, 'WHERE Id IN')) {
                    return [];
                }

                return [[
                    'Id' => '005-profile-change', 'Name' => 'Cambio perfil', 'Email' => null,
                    'CreatedDate' => '2026-04-10T14:11:59.000+0000',
                    'Profile' => ['Name' => 'Marketing'], 'USR_SEL_Delegacion__c' => 'HR MOTOR ALICANTE',
                    'Comision_Tasador__c' => false, 'IsActive' => true,
                ]];
            }
        };

        $result = (new SalesforceMonthlyUsersSyncService($client))->sync();
        app(CommercialDelegationSnapshotService::class)->captureCurrentUsers(CarbonImmutable::parse('2026-08-26 07:15:00', 'UTC'));

        $this->assertSame(1, $result['tracked_refresh_queries']);
        $this->assertSame('Marketing', SalesforceUser::query()->value('profile_name'));
        $this->assertTrue(SalesforceUser::query()->firstOrFail()->is_active);
        $this->assertSame(
            '2026-04-10 14:11:59',
            SalesforceUser::query()->firstOrFail()->created_date->utc()->format('Y-m-d H:i:s')
        );
        $this->assertSame(0, CommercialDelegationSnapshot::query()->whereNull('observed_until')->count());
        $this->assertStringContainsString("'005-profile-change'", implode("\n", $client->queries));
        $this->assertStringContainsString('CreatedDate', implode("\n", $client->queries));
    }

    public function test_roster_contact_center_se_refresca_en_un_unico_batch_sin_ampliar_la_query_principal(): void
    {
        foreach (['005Qx00000RosterA1', '005Qx00000RosterB2', '005Qx00000RosterC3'] as $id) {
            ContactCenterCommissionRosterMember::query()->create([
                'salesforce_user_id' => $id,
                'display_name' => 'Agente roster',
                'effective_from' => '2026-09-01',
            ]);
        }

        $this->assertDatabaseMissing('salesforce_users', ['salesforce_id' => '005Qx00000RosterA1']);

        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                if (! str_contains($soql, 'WHERE Id IN')) {
                    return [];
                }

                return [[
                    'Id' => '005Qx00000RosterA1', 'Name' => 'Agente Salesforce', 'Email' => null,
                    'CreatedDate' => '2026-09-09T07:42:09.000+0000', 'Profile' => ['Name' => 'CALL COMUNIDAD'],
                    'USR_SEL_Delegacion__c' => null, 'Comision_Tasador__c' => false, 'IsActive' => true,
                ]];
            }
        };

        $result = (new SalesforceMonthlyUsersSyncService($client))->sync();
        $tracked = collect($client->queries)->filter(fn (string $query): bool => str_contains($query, 'WHERE Id IN'));

        $this->assertSame(1, $result['tracked_refresh_queries']);
        $this->assertCount(1, $tracked);
        $this->assertStringContainsString("'005Qx00000RosterA1'", $tracked->first());
        $this->assertStringNotContainsString('CALL COMUNIDAD', $client->queries[0]);
        $this->assertDatabaseHas('salesforce_users', ['salesforce_id' => '005Qx00000RosterA1', 'profile_name' => 'CALL COMUNIDAD']);
    }
}
