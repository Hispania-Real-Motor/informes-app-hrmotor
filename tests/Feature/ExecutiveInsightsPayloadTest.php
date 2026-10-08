<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class ExecutiveInsightsPayloadTest extends TestCase
{
    use MirrorsLeadFixturesToInterestReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::clear();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-13 12:00:00'));
        config()->set('openai.enabled', true);
        config()->set('openai.api_key', 'test-key');

        SalesforceUser::create([
            'salesforce_id' => '005-commercial',
            'name' => 'Comercial',
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR TORREJON',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_payload_ia_contiene_agregados_y_no_leads_individuales_ni_api_names(): void
    {
        SalesforceLead::create([
            'salesforce_id' => '00Q-secret',
            'name' => 'Lead Privado',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'owner_id' => '005-commercial',
            'portal_text' => 'Web',
            'delegacion_encargada_text' => 'HR MOTOR TORREJON',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => '{"insights":[]}']]],
            ]),
        ]);

        $this->getJson('/informes/leads/data/summary')->assertOk();

        Http::assertSent(function ($request) {
            $content = data_get($request->data(), 'messages.1.content');

            return str_contains($content, '"kpis"')
                && str_contains($content, '"comparativa"')
                && str_contains($content, '"rankings"')
                && ! str_contains($content, '00Q-secret')
                && ! str_contains($content, 'Lead Privado')
                && ! str_contains($content, 'RecordType.Name')
                && ! str_contains($content, 'Delegacion_Encargada_Text__c')
                && ! str_contains($content, 'OwnerId');
        });
    }
}
