<?php

namespace Tests\Unit;

use App\Services\Reports\ContactCenterCommissions\ContactCenterCommissionRosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContactCenterCommissionRosterServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_y_recupera_miembro_vigente_por_fecha_y_mes(): void
    {
        $service = app(ContactCenterCommissionRosterService::class);
        $service->save($this->member(['effective_until' => '2026-10-15']));

        $this->assertCount(1, $service->membersForDate('2026-10-15')->where('salesforce_user_id', '005Qx00000RosterAA'));
        $this->assertCount(0, $service->membersForDate('2026-10-16')->where('salesforce_user_id', '005Qx00000RosterAA'));
        $this->assertCount(1, $service->membersForMonth('2026-10')->where('salesforce_user_id', '005Qx00000RosterAA'));
    }

    public function test_rechaza_fin_anterior_al_inicio_y_solapes(): void
    {
        $service = app(ContactCenterCommissionRosterService::class);

        try {
            $service->save($this->member(['effective_until' => '2026-08-31']));
            $this->fail('Expected invalid effective range.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_until', $exception->errors());
        }

        $service->save($this->member(['effective_until' => '2026-12-31']));

        try {
            $service->save($this->member(['effective_from' => '2026-11-01', 'effective_until' => null]));
            $this->fail('Expected overlapping roster range.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_from', $exception->errors());
        }

        $service->save($this->member(['effective_from' => '2027-01-01', 'effective_until' => null]));
        $this->assertContains('005Qx00000RosterAA', $service->trackedUserIds());
    }

    /** @return array{salesforce_user_id:string,display_name:string,effective_from:string,effective_until:?string} */
    private function member(array $overrides = []): array
    {
        return array_replace([
            'salesforce_user_id' => '005Qx00000RosterAA',
            'display_name' => 'Nombre de presentación',
            'effective_from' => '2026-09-01',
            'effective_until' => null,
        ], $overrides);
    }
}
