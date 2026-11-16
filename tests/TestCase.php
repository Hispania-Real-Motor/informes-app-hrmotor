<?php

namespace Tests;

use App\Models\ReportUser;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected bool $authenticateReportsByDefault = true;

    protected function setUp(): void
    {
        // Production code may set a request-scoped limit; do not leak it into
        // the next test executed by the same long-lived PHPUnit process.
        @set_time_limit(0);

        parent::setUp();

        if (! $this->authenticateReportsByDefault
            || ! str_contains(static::class, '\\Feature\\')) {
            return;
        }

        if (! Schema::hasTable('report_users')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        $email = 'default-test-admin-'.sha1(static::class.'::'.$this->name()).'@example.test';
        $user = ReportUser::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Default test admin',
                'password' => Hash::make('default-test-password'),
                'role' => ReportUser::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        $this->withSession([
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
        ]);
    }
}
