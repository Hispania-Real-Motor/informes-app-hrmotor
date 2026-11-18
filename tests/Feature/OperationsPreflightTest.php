<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationsPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_reports_environment_without_running_an_operational_task(): void
    {
        $this->artisan('operations:preflight')
            ->assertSuccessful()
            ->expectsOutputToContain('"cache_round_trip":{"ok":true');

        $this->assertDatabaseCount('operational_task_runs', 0);
    }

    public function test_preflight_detects_a_non_writable_cache_entry_without_exposing_its_contents(): void
    {
        $path = config('cache.stores.file.path');
        if (! is_string($path) || ! is_dir($path)) {
            $this->markTestSkipped('El store de archivos no está disponible en este entorno.');
        }

        $directory = $path.'/preflight-'.Str::uuid();
        $file = $directory.'/entry';
        mkdir($directory, 0755, true);
        file_put_contents($file, 'synthetic-secret-payload');
        chmod($file, 0444);
        chmod($directory, 0555);
        if (is_writable($file)) {
            chmod($directory, 0755);
            @unlink($file);
            @rmdir($directory);
            $this->markTestSkipped('El sistema de archivos no permite simular permisos no escribibles.');
        }

        try {
            $result = $this->artisan('operations:preflight');
            $result->assertFailed();
            $result->expectsOutputToContain('"cache_entries":{"ok":false');
            $result->doesntExpectOutputToContain('synthetic-secret-payload');
        } finally {
            chmod($directory, 0755);
            chmod($file, 0644);
            unlink($file);
            rmdir($directory);
        }
    }

    public function test_preflight_removes_its_temporary_cache_entry(): void
    {
        $this->artisan('operations:preflight')->assertSuccessful()
            ->expectsOutputToContain('"cache_round_trip":{"ok":true,"cleaned":true');
    }

    public function test_preflight_reports_unverified_cleanup_when_cache_initialization_fails(): void
    {
        config(['cache.default' => 'missing-preflight-store']);

        $this->artisan('operations:preflight')->assertFailed()
            ->expectsOutputToContain('"cache_round_trip":{"ok":false,"cleaned":false')
            ->doesntExpectOutputToContain('Exception')
            ->doesntExpectOutputToContain('secret');
    }
}
