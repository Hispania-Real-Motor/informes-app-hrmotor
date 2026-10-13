<?php

namespace Tests\Feature;

use App\Models\AnalyticalRuleSet;
use App\Models\ReportUser;
use App\Models\SeoExecutiveEmailSetting;
use App\Services\SeoAnalytics\SeoExecutiveMailReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SeoExecutiveEmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.from.address' => 'reports@example.test',
        ]);
        Http::preventStrayRequests();
    }

    public function test_migration_has_bounded_settings_without_report_user_foreign_key(): void
    {
        $this->assertTrue(Schema::hasColumns('seo_executive_email_settings', [
            'module_key', 'recipients', 'updated_by_report_user_id',
        ]));
        $this->assertTrue(Schema::hasColumns('seo_executive_daily_reports', [
            'report_date', 'generated_at', 'payload', 'payload_hash',
        ]));
        $this->assertTrue(Schema::hasColumns('seo_executive_email_deliveries', [
            'seo_executive_daily_report_id', 'report_date', 'recipient_email', 'recipient_hash',
            'status', 'attempt_count', 'last_attempt_at', 'sent_at', 'error_message',
        ]));
        $foreignTables = collect(\DB::select("PRAGMA foreign_key_list('seo_executive_email_settings')"));
        $this->assertCount(0, $foreignTables);
    }

    public function test_admin_and_director_can_manage_recipients_independently_from_rules(): void
    {
        foreach ([ReportUser::ROLE_ADMIN, ReportUser::ROLE_DIRECTOR] as $role) {
            $user = $this->user($role, $role.'-executive-email@example.test');
            $this->authenticate($user);

            $this->get(route('reports.seo-analytics.settings.index'))
                ->assertOk()
                ->assertSee('Correo ejecutivo diario')
                ->assertSee('Transporte de correo operativo');
            $this->put(route('reports.seo-analytics.settings.email.update'), [
                'email_recipients' => " Direction@Example.Test \ndirection@example.test\nseo@example.test",
            ])->assertRedirect(route('reports.seo-analytics.settings.index'));

            $setting = SeoExecutiveEmailSetting::query()->where('module_key', 'seo')->sole();
            $this->assertSame(['direction@example.test', 'seo@example.test'], $setting->recipients);
            $this->assertSame($user->id, $setting->updated_by_report_user_id);
            $this->assertSame(2, AnalyticalRuleSet::query()->count());
        }

        Http::assertNothingSent();
    }

    public function test_other_roles_cannot_read_or_update_email_settings(): void
    {
        foreach ([
            ReportUser::ROLE_VIEWER,
            ReportUser::ROLE_MARKETING,
            ReportUser::ROLE_AREA_MANAGER,
            ReportUser::ROLE_DELEGATION_MANAGER,
            ReportUser::ROLE_COMMERCIAL,
            ReportUser::ROLE_FINANCIAL,
            ReportUser::ROLE_COMMISSION_AUDITOR,
        ] as $role) {
            $this->authenticate($this->user($role, $role.'-executive-email@example.test'));
            $this->get(route('reports.seo-analytics.settings.index'))->assertForbidden();
            $this->put(route('reports.seo-analytics.settings.email.update'), [
                'email_recipients' => 'recipient@example.test',
            ])->assertForbidden();
        }

        $this->assertSame(0, SeoExecutiveEmailSetting::query()->count());
    }

    public function test_recipient_validation_accepts_one_to_ten_and_rejects_invalid_or_more_than_ten(): void
    {
        $this->authenticate($this->user(ReportUser::ROLE_ADMIN, 'admin-email-validation@example.test'));

        $this->put(route('reports.seo-analytics.settings.email.update'), [
            'email_recipients' => 'one@example.test',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['one@example.test'], SeoExecutiveEmailSetting::query()->sole()->recipients);

        $ten = collect(range(1, 10))->map(fn (int $number): string => "recipient{$number}@example.test")->implode("\n");
        $this->put(route('reports.seo-analytics.settings.email.update'), [
            'email_recipients' => $ten,
        ])->assertSessionHasNoErrors();
        $this->assertCount(10, SeoExecutiveEmailSetting::query()->sole()->recipients);

        $eleven = collect(range(1, 11))->map(fn (int $number): string => "recipient{$number}@example.test")->implode("\n");
        $this->put(route('reports.seo-analytics.settings.email.update'), [
            'email_recipients' => $eleven,
        ])->assertSessionHasErrors('email_recipients');
        $this->put(route('reports.seo-analytics.settings.email.update'), [
            'email_recipients' => 'not-an-email',
        ])->assertSessionHasErrors('email_recipients.0');
        $this->put(route('reports.seo-analytics.settings.email.update'), [
            'email_recipients' => "valid@example.test\n\nsecond@example.test",
        ])->assertSessionHasErrors('email_recipients.1');
        $this->put(route('reports.seo-analytics.settings.email.update'), [
            'email_recipients' => "\n\n",
        ])->assertSessionHasErrors('email_recipients');

        $this->assertCount(10, SeoExecutiveEmailSetting::query()->sole()->recipients);
        Http::assertNothingSent();
    }

    public function test_mail_readiness_rejects_non_delivery_transports_and_default_sender(): void
    {
        $readiness = app(SeoExecutiveMailReadinessService::class);

        foreach (['log', 'array'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->assertFalse($readiness->ready());
        }

        config(['mail.default' => 'smtp', 'mail.from.address' => 'hello@example.com']);
        $this->assertFalse($readiness->ready());
        config(['mail.from.address' => 'reports@example.test']);
        $this->assertTrue($readiness->ready());
    }

    private function user(string $role, string $email): ReportUser
    {
        return ReportUser::query()->create([
            'name' => 'Test '.$role,
            'email' => $email,
            'password' => 'test-password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function authenticate(ReportUser $user): void
    {
        $this->withSession([
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
        ]);
    }
}
