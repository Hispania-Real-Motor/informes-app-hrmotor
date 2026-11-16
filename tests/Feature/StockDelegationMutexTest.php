<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\Stock\SalesforceSaleSnapshotService;
use App\Services\Reports\Stock\SalesforceVehicleSyncService;
use App\Services\Reports\Stock\StockCapacityImportService;
use App\Services\Reports\Stock\StockDelegationConsolidationService;
use App\Services\Reports\Stock\StockDelegationMutex;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class StockDelegationMutexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('stock.delegation_mutex.store', 'array');
        config()->set('stock.delegation_mutex.key', 'test:stock-delegation-writes');
        config()->set('stock.delegation_mutex.ttl_seconds', 60);
        config()->set('stock.delegation_mutex.wait_seconds', 0);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Cache::store('array')->lock('test:stock-delegation-writes', 1)->forceRelease();

        parent::tearDown();
    }

    public function test_independent_owner_rejects_a_concurrent_writer_without_releasing_it(): void
    {
        $existing = Cache::store('array')->lock('test:stock-delegation-writes', 60);
        $this->assertTrue($existing->get());

        try {
            app(StockDelegationMutex::class)->run(fn (): bool => true);
            $this->fail('El segundo escritor debía ser rechazado.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Otra operación de Stock', $exception->getMessage());
        }

        $this->assertTrue($existing->isOwnedByCurrentProcess());
        $this->assertTrue($existing->release());
    }

    public function test_exception_releases_the_owned_lock_for_a_retry(): void
    {
        try {
            app(StockDelegationMutex::class)->run(
                fn () => throw new LogicException('fallo controlado'),
            );
            $this->fail('La excepción de la operación debía propagarse.');
        } catch (LogicException $exception) {
            $this->assertSame('fallo controlado', $exception->getMessage());
        }

        $retry = Cache::store('array')->lock('test:stock-delegation-writes', 60);
        $this->assertTrue($retry->get());
        $retry->release();
    }

    public function test_expired_lock_can_be_reacquired(): void
    {
        CarbonImmutable::setTestNow('2026-11-16 10:00:00');
        $abandoned = Cache::store('array')->lock('test:stock-delegation-writes', 1);
        $this->assertTrue($abandoned->get());

        CarbonImmutable::setTestNow('2026-11-16 10:00:02');
        $replacement = Cache::store('array')->lock('test:stock-delegation-writes', 60);
        $this->assertTrue($replacement->get());
        $this->assertFalse($abandoned->release());
        $this->assertTrue($replacement->isOwnedByCurrentProcess());
        $replacement->release();
    }

    public function test_expired_owner_does_not_release_a_foreign_replacement(): void
    {
        config()->set('stock.delegation_mutex.ttl_seconds', 1);
        $replacement = null;

        app(StockDelegationMutex::class)->run(function () use (&$replacement): void {
            CarbonImmutable::setTestNow(now()->addSeconds(2));
            $replacement = Cache::store('array')->lock('test:stock-delegation-writes', 60);
            $this->assertTrue($replacement->get());
        });

        $this->assertTrue($replacement->isOwnedByCurrentProcess());
        $replacement->release();
    }

    public function test_all_service_entry_points_share_the_same_mutex(): void
    {
        $existing = Cache::store('array')->lock('test:stock-delegation-writes', 60);
        $this->assertTrue($existing->get());
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                return [];
            }
        };
        $vehicleSync = app()->makeWith(SalesforceVehicleSyncService::class, ['client' => $client]);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stock-mutex-'.uniqid().'.csv';
        file_put_contents($path, "Tienda;Plazas totales\nRivas;320\n");

        try {
            $this->assertContended(fn () => $vehicleSync->sync());
            $this->assertContended(fn () => app(StockDelegationConsolidationService::class)->consolidate([]));
            $this->assertContended(fn () => app(StockCapacityImportService::class)->import($path, ';'));
            $this->assertContended(fn () => app(SalesforceSaleSnapshotService::class)->capture(new SalesforceOpportunity));
        } finally {
            @unlink($path);
            $existing->release();
        }
    }

    public function test_non_shared_cache_drivers_are_rejected_even_in_tests(): void
    {
        config()->set('stock.delegation_mutex.store', 'file');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cache store compartido');
        app(StockDelegationMutex::class)->run(fn (): bool => true);
    }

    private function assertContended(callable $operation): void
    {
        try {
            $operation();
            $this->fail('La entrada de escritura debía respetar el mutex compartido.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Otra operación de Stock', $exception->getMessage());
        }
    }
}
