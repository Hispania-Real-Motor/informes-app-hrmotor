<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class OperationsPreflightCommand extends Command
{
    protected $signature = 'operations:preflight';

    protected $description = 'Comprueba el entorno del scheduler sin ejecutar tareas de integración.';

    public function handle(): int
    {
        $paths = [
            'cache_path' => config('cache.stores.file.path'),
            'storage_framework' => storage_path('framework'),
            'storage_logs' => storage_path('logs'),
            'bootstrap_cache' => base_path('bootstrap/cache'),
        ];
        $pathChecks = [];
        foreach ($paths as $name => $path) {
            $pathChecks[$name] = [
                'configured' => is_string($path) && $path !== '',
                'directory' => is_string($path) && is_dir($path),
                'readable' => is_string($path) && is_readable($path),
                'writable' => is_string($path) && is_writable($path),
            ];
        }

        $cache = $this->cacheRoundTrip();
        $cacheEntries = $this->cacheEntries($paths['cache_path']);
        $database = $this->databaseCheck();
        $identityOk = ! $this->requiresWwwData()
            || (function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                && (posix_getpwuid(posix_geteuid())['name'] ?? null) === 'www-data');
        $pathsOk = collect($pathChecks)->every(static fn (array $check): bool =>
            $check['configured'] && $check['directory'] && $check['readable'] && $check['writable']);
        $ok = $pathsOk && $cache['ok'] && $cacheEntries['ok'] && $database['ok'] && $identityOk;

        $this->line(json_encode([
            'ok' => $ok,
            'effective_uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
            'effective_user' => function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null,
            'expected_user' => $this->requiresWwwData() ? 'www-data' : null,
            'identity_ok' => $identityOk,
            'configuration_cached' => app()->configurationIsCached(),
            'cache_store' => config('cache.default'),
            'paths' => $pathChecks,
            'cache_entries' => $cacheEntries,
            'cache_round_trip' => $cache,
            'database' => $database,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{ok:bool, cleaned:bool} */
    private function cacheRoundTrip(): array
    {
        $store = (string) config('cache.default');
        $key = 'operations-preflight:'.Str::uuid();
        $cache = null;
        $created = false;
        $ok = false;
        $cleaned = false;
        try {
            $cache = Cache::store($store);
            $cache->put($key, 'ok', 60);
            $created = true;
            $read = $cache->get($key) === 'ok';
            $ok = $read;
        } catch (Throwable) {
            $ok = false;
        } finally {
            if ($created && $cache !== null) {
                try {
                    $cache->forget($key);
                    $cleaned = $cache->get($key) === null;
                } catch (Throwable) {
                    $cleaned = false;
                }
            }
        }

        return ['ok' => $ok && $cleaned, 'cleaned' => $cleaned];
    }

    /** @return array{ok:bool,checked:int,unwritable:int,truncated:bool} */
    private function cacheEntries(mixed $path): array
    {
        if (! is_string($path) || ! is_dir($path) || ! is_readable($path)) {
            return ['ok' => false, 'checked' => 0, 'unwritable' => 0, 'truncated' => false];
        }

        $checked = 0;
        $unwritable = 0;
        $truncated = false;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $entry) {
                if ($entry->isLink()) {
                    continue;
                }
                if (++$checked > 1000) {
                    $truncated = true;
                    break;
                }
                if (! $entry->isReadable() || ! $entry->isWritable()) {
                    $unwritable++;
                }
            }
        } catch (Throwable) {
            return ['ok' => false, 'checked' => $checked, 'unwritable' => $unwritable, 'truncated' => $truncated];
        }

        return ['ok' => $unwritable === 0 && ! $truncated, 'checked' => $checked,
            'unwritable' => $unwritable, 'truncated' => $truncated];
    }

    private function requiresWwwData(): bool
    {
        return app()->environment('production') && DIRECTORY_SEPARATOR === '/';
    }

    /** @return array{ok:bool} */
    private function databaseCheck(): array
    {
        try {
            DB::select('SELECT 1');

            return ['ok' => true];
        } catch (Throwable) {
            return ['ok' => false];
        }
    }
}
