<?php

namespace App\Console\Commands;

use App\Services\Reports\CommercialCommissions\CommercialCommissionDelegationReviewsService;
use App\Services\Reports\CommercialCommissions\StoreManagerCommissionTargetService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshCommercialCommissionInternalReviews extends Command
{
    protected $signature = 'commissions:refresh-internal-reviews
        {--month= : Explicit month in YYYY-MM format}
        {--months=2 : Number of current and previous months to refresh}';

    protected $description = 'Refresh internal delegation reviews for the store-manager commission plan';

    public function handle(
        StoreManagerCommissionTargetService $targets,
        CommercialCommissionDelegationReviewsService $reviews,
    ): int {
        $months = $this->monthsToRefresh();
        if ($months === null) {
            return self::FAILURE;
        }

        $failed = false;
        foreach ($months as $month) {
            try {
                $monthTargets = $targets->forMonth($month);
                $labels = collect($monthTargets)->pluck('store_name')->filter()->unique()->values();

                if ($labels->isEmpty()) {
                    $this->comment(sprintf('No hay objetivos de tienda para %s; no se consulta la fuente de reseñas.', $month->format('Y-m')));

                    continue;
                }

                $payloads = $reviews->forMonthAndDelegations($month, $labels, true);
                $counts = collect($payloads)->countBy('technical_status');
                $summary = $counts->map(fn (int $count, string $status): string => $status.'='.$count)->implode(', ');
                $this->line(sprintf('%s: %s', $month->format('Y-m'), $summary !== '' ? $summary : 'sin resultados'));
                $failed = $failed || $counts->keys()->intersect([
                    'not_configured', 'transport_error', 'remote_error', 'invalid_payload', 'not_mapped',
                ])->isNotEmpty();
            } catch (Throwable $exception) {
                // Do not expose endpoint, credentials or response bodies in CLI
                // output. OperationalTaskService persists only a generic error.
                Log::warning('Fallo controlado al refrescar reseñas internas desde el comando.', [
                    'integration' => 'internal_reviews',
                    'status' => 'command_error',
                    'exception_type' => $exception::class,
                    'month' => $month->format('Y-m'),
                ]);
                $this->error(sprintf('No se pudo refrescar la fuente de reseñas para %s.', $month->format('Y-m')));
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, CarbonImmutable>|null */
    private function monthsToRefresh(): ?array
    {
        $explicit = $this->option('month');
        if (is_string($explicit) && $explicit !== '') {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $explicit) !== 1) {
                $this->error('El mes debe tener formato YYYY-MM.');

                return null;
            }

            return [CarbonImmutable::createFromFormat('!Y-m', $explicit, 'Europe/Madrid')->startOfMonth()];
        }

        $count = filter_var($this->option('months'), FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > 12) {
            $this->error('El número de meses debe estar entre 1 y 12.');

            return null;
        }

        $current = CarbonImmutable::now('Europe/Madrid')->startOfMonth();

        return Collection::times($count, fn (int $offset): CarbonImmutable => $current->subMonths($offset - 1))->all();
    }
}
