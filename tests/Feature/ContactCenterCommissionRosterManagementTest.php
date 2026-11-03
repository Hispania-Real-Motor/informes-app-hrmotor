<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ContactCenterCommissionRosterManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_puede_acceder_a_la_administracion_del_roster(): void
    {
        $this->withSession($this->sessionFor(ReportUser::ROLE_ADMIN))
            ->get('/informes/configuracion-comisiones/contact-center-roster')
            ->assertOk()
            ->assertSee('Roster Contact Center');
    }

    public function test_usuario_sin_permiso_no_puede_leer_ni_escribir_el_roster(): void
    {
        $session = $this->sessionFor(ReportUser::ROLE_VIEWER);

        $this->withSession($session)
            ->get('/informes/configuracion-comisiones/contact-center-roster')
            ->assertForbidden();

        $this->withSession($session)
            ->post('/informes/configuracion-comisiones/contact-center-roster', [
                'salesforce_user_id' => '005Qx00000RosterA1',
                'display_name' => 'No autorizado',
                'effective_from' => '2026-09-01',
            ])
            ->assertForbidden();
    }

    private function sessionFor(string $role): array
    {
        config()->set('services.informes_auth.enabled', true);
        $user = ReportUser::query()->create([
            'name' => 'Usuario prueba',
            'email' => $role.'@example.test',
            'password' => Hash::make('secret'),
            'role' => $role,
            'is_active' => true,
        ]);

        return [
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $role,
            'report_user_email' => $user->email,
        ];
    }
}
