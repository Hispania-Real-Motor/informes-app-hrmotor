<?php

namespace App\Services\Reports\Stock;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class StockDelegationMutex
{
    private const MINIMUM_PRODUCTION_TTL_SECONDS = 10800;

    private const MAXIMUM_WAIT_SECONDS = 30;

    /** @var array<int, string> */
    private const DISTRIBUTED_DRIVERS = ['database', 'redis', 'memcached', 'dynamodb'];

    public function run(Closure $operation): mixed
    {
        $store = (string) (config('stock.delegation_mutex.store') ?: config('cache.default'));
        $driver = (string) config("cache.stores.{$store}.driver");

        if (! in_array($driver, self::DISTRIBUTED_DRIVERS, true)
            && ! (app()->environment('testing') && $driver === 'array')) {
            throw new RuntimeException(
                "El mutex de delegaciones de Stock requiere un cache store compartido; '{$store}' usa '{$driver}'.",
            );
        }

        $key = (string) config('stock.delegation_mutex.key', 'stock:delegation-writes');
        $ttl = max((int) config('stock.delegation_mutex.ttl_seconds', 14400), 1);
        $wait = min(max((int) config('stock.delegation_mutex.wait_seconds', 5), 0), self::MAXIMUM_WAIT_SECONDS);
        if ($key === '') {
            throw new RuntimeException('La clave del mutex de delegaciones de Stock no puede estar vacía.');
        }
        if (! app()->environment('testing') && $ttl < self::MINIMUM_PRODUCTION_TTL_SECONDS) {
            throw new RuntimeException('El TTL del mutex de delegaciones de Stock debe ser de al menos tres horas.');
        }

        $lock = Cache::store($store)->lock($key, $ttl);

        try {
            $lock->block($wait);
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'Otra operación de Stock está modificando delegaciones o capacidades; reinténtelo más tarde.',
                previous: $exception,
            );
        }

        try {
            return $operation();
        } finally {
            try {
                // Laravel only releases when the owner token still matches.
                $lock->release();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
