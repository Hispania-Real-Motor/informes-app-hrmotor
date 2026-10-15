<?php

namespace App\Services\Operations;

use App\Models\OperationalAlert;
use App\Support\IntegrationErrorSanitizer;
use Illuminate\Support\Facades\DB;

class OperationalAlertService
{
    public function open(
        string $type,
        string $severity,
        string $source,
        string $technicalIdentifier,
        string $message,
        array $context = [],
    ): OperationalAlert {
        $fingerprint = $this->fingerprint($type, $source, $technicalIdentifier);

        return DB::transaction(function () use (
            $fingerprint,
            $type,
            $severity,
            $source,
            $technicalIdentifier,
            $message,
            $context,
        ): OperationalAlert {
            $now = now();
            $alert = OperationalAlert::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();

            if ($alert === null) {
                $created = OperationalAlert::query()->createOrFirst(['fingerprint' => $fingerprint], [
                    'fingerprint' => $fingerprint,
                    'type' => $type,
                    'severity' => $severity,
                    'source' => $source,
                    'state' => OperationalAlert::STATE_OPEN,
                    'message' => IntegrationErrorSanitizer::sanitizeMessage($message),
                    'technical_identifier' => $technicalIdentifier,
                    'context' => IntegrationErrorSanitizer::sanitizeContext($context),
                    'first_detected_at' => $now,
                    'last_detected_at' => $now,
                    'occurrences' => 1,
                ]);
                if ($created->wasRecentlyCreated) {
                    return $created;
                }
                $alert = OperationalAlert::query()->whereKey($created->id)->lockForUpdate()->firstOrFail();
            }

            $reopening = $alert->state === OperationalAlert::STATE_RESOLVED;
            $alert->update([
                'type' => $type,
                'severity' => $severity,
                'source' => $source,
                'state' => OperationalAlert::STATE_OPEN,
                'message' => IntegrationErrorSanitizer::sanitizeMessage($message),
                'technical_identifier' => $technicalIdentifier,
                'context' => IntegrationErrorSanitizer::sanitizeContext($context),
                'first_detected_at' => $reopening ? $now : $alert->first_detected_at,
                'last_detected_at' => $now,
                'occurrences' => $reopening ? 1 : $alert->occurrences + 1,
                'resolution' => null,
                'resolved_at' => null,
            ]);

            return $alert->refresh();
        }, 3);
    }

    public function resolve(
        string $type,
        string $source,
        string $technicalIdentifier,
        string $resolution,
    ): bool {
        return OperationalAlert::query()
            ->where('fingerprint', $this->fingerprint($type, $source, $technicalIdentifier))
            ->where('state', OperationalAlert::STATE_OPEN)
            ->update([
                'state' => OperationalAlert::STATE_RESOLVED,
                'resolution' => IntegrationErrorSanitizer::sanitizeMessage($resolution),
                'resolved_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }

    private function fingerprint(string $type, string $source, string $technicalIdentifier): string
    {
        return hash('sha256', implode('|', [$type, $source, $technicalIdentifier]));
    }
}
