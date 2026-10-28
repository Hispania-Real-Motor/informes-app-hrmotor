<?php

namespace App\Providers;

use App\Services\Salesforce\InterestGenerationReader;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(InterestGenerationReader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Filesystem $files): void
    {
        $this->ensureFileCacheDirectories($files);
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }

    private function ensureFileCacheDirectories(Filesystem $files): void
    {
        $stores = config('cache.stores', []);

        foreach ($stores as $store) {
            if (($store['driver'] ?? null) !== 'file') {
                continue;
            }

            foreach (['path', 'lock_path'] as $key) {
                $path = $store[$key] ?? null;

                if (! is_string($path) || $path === '') {
                    continue;
                }

                $files->ensureDirectoryExists($path, 0755, true);
            }
        }
    }
}
