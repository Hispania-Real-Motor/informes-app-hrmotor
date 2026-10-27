<?php

namespace App\Services\Operations;

use Symfony\Component\Console\Output\Output;

/**
 * Discards output immediately. Retains only outcome flags for existing commands
 * whose CLI success code also covers documented skips/partial connector errors.
 * No line, response body or exception text is retained.
 */
final class OperationalTaskOutput extends Output
{
    public bool $skipped = false;

    public bool $partialFailure = false;

    public function __construct(private readonly string $taskKey)
    {
        parent::__construct(self::VERBOSITY_NORMAL, false);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        if (in_array($this->taskKey, ['seo-sync-search-console', 'seo-sync-ga4-organic', 'seo-sync-technical-health'], true)
            && str_starts_with(trim($message), 'SKIPPED:')) {
            $this->skipped = true;
        }
        if (in_array($this->taskKey, ['campaigns-sync-meta', 'campaigns-sync-google'], true)) {
            if (str_contains($message, 'Ads configurado: no') || str_contains($message, 'Intervalo omitido:')) {
                $this->skipped = true;
            }
            if (str_contains($message, 'No se ha podido actualizar')) {
                $this->partialFailure = true;
            }
        }
    }
}
