<?php

namespace App\Services\Reports\Calls;

use Illuminate\Support\Str;

class CallClassificationRules
{
    public const VERSION = '2026-10-08.1';

    public const CUSTOMER_SERVICE_LABEL = 'Atención al Cliente';

    public const CONTACT_CENTER_LABEL = 'Contact Center';

    public const APPRAISER_LABEL = 'Tasadores';

    public const OVERFLOW_REASON_PORTAL_ATTENDED_BY_SUPPORT = 'portal_attended_by_support_team';

    public const EXCLUDED_TEST_PROFILE = 'Pruebas comunidad comercial';

    public const EXCLUDED_TEST_PROFILE_REASON = 'excluded_test_profile';

    public const UNASSIGNED_TEAM = 'unassigned';

    private const FORCED_TEAM_BY_COMPACT_NAME = [
        // Comerciales
        'carlosmelero' => 'commercial',
        'manuelsantamargarita' => 'commercial',
        'jorgemartin' => 'commercial',

        // Atención al Cliente
        'carolinagayarre' => 'customer_service',
        'marielaherrera' => 'customer_service',
        'jessicaperez' => 'customer_service',
        'laurahernandez' => 'customer_service',
        'siomaraclemente' => 'customer_service',
        'susanagomez' => 'customer_service',
        'vanessasanjuan' => 'customer_service',
        'callcenterfontellas' => 'customer_service',
        'miriamgonzalez' => 'customer_service',
        'glenisfalcon' => 'customer_service',
        'jenniferguzman' => 'customer_service',

        // Tasadores
        'alexandragarcia' => 'appraiser',
        'germanolsen' => 'appraiser',
        'aimarvillalba' => 'appraiser',
        'josemariabailon' => 'appraiser',

        // Contact Center
        'yuleidisgarcia' => 'contact_center',
        'mariavidal' => 'contact_center',
        'mariavidalperez' => 'contact_center',
        'vanesagerman' => 'contact_center',
        'joseignaciopalomo' => 'contact_center',
        'josepalomocasas' => 'contact_center',
        'joseignaciopalomocasas' => 'contact_center',
        'nurialarrosa' => 'contact_center',
    ];

    private const HIDDEN_COMPACT_NAMES = [
        'carlossoria',
    ];

    public function forcedTeamForName(?string $name): ?string
    {
        $compact = str_replace(' ', '', $this->normalizeName($name));

        return self::FORCED_TEAM_BY_COMPACT_NAME[$compact] ?? null;
    }

    public function isHiddenOperationalUser(?string $name): bool
    {
        $compact = str_replace(' ', '', $this->normalizeName($name));

        return in_array($compact, self::HIDDEN_COMPACT_NAMES, true);
    }

    public function isExcludedTestProfile(?string $profile): bool
    {
        return trim((string) $profile) === self::EXCLUDED_TEST_PROFILE;
    }

    public function adjustedDuration(?int $duration, ?string $origin): int
    {
        $duration = max(0, (int) $duration);

        return max(0, $duration - ($origin === 'commercial_direct' ? 5 : 10));
    }

    public function normalizeName(?string $name): string
    {
        return Str::of($this->cleanUserName($name))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    public function isSystemIdentity(?string $name, ?string $profile): bool
    {
        $nameKey = $this->normalizeName($name);
        $profile = (string) $profile;
        $compact = str_replace(' ', '', $nameKey);

        return in_array($nameKey, ['carlos torres', 'platform integration user', 'api user'], true)
            || in_array($compact, self::HIDDEN_COMPACT_NAMES, true)
            || str_contains($profile, 'System Administrator')
            || str_contains($profile, 'Administrator');
    }

    public function isCustomerServiceSpecialName(?string $name): bool
    {
        $compact = str_replace(' ', '', $this->normalizeName($name));

        return in_array($compact, ['vanessasanjuan', 'vanesasanjuan', 'callcenterfontellas'], true);
    }

    public function effectiveTeam(?string $team, ?string $name = null, ?string $profile = null): string
    {
        if ($this->isSystemIdentity($name, $profile)) {
            return 'system';
        }

        $forcedTeam = $this->forcedTeamForName($name);

        if ($forcedTeam !== null) {
            return $forcedTeam;
        }

        if ($this->isCustomerServiceSpecialName($name)) {
            return 'customer_service';
        }

        return match ($team) {
            'commercial', 'customer_service', 'contact_center', 'appraiser', 'system' => $team,
            default => self::UNASSIGNED_TEAM,
        };
    }

    public function effectiveOrigin(?string $origin, ?string $portalesRaw): string
    {
        $portalKey = $this->normalizeName($portalesRaw);

        if ($portalesRaw === null || $portalKey === 'llamada directa' || $origin === 'switchboard') {
            return 'commercial_direct';
        }

        return $origin === 'commercial_direct' ? 'commercial_direct' : 'portal';
    }

    public function isOperationalTeam(?string $team): bool
    {
        return in_array($team, ['commercial', 'customer_service', 'contact_center', 'appraiser'], true);
    }

    public function effectiveDelegationZone(?string $team, ?string $delegation, ?string $zone): array
    {
        return [
            'delegation' => filled($delegation) ? $delegation : 'Sin clasificar',
            'zone' => filled($zone) ? $zone : 'Sin clasificar',
        ];
    }

    public function userGroupKey(
        ?string $team,
        ?string $operationalUserId,
        ?string $operationalUserName,
        ?string $destinationAgentName,
        ?string $ownerName,
        ?string $ownerProfile,
    ): string {
        if ($this->isSystemIdentity($operationalUserName ?: $ownerName, $ownerProfile)) {
            return 'system';
        }

        $nameKey = $this->normalizedCanonicalUserKey($operationalUserName, $destinationAgentName, $ownerName);

        if ($nameKey !== '') {
            return ($team ?: 'unknown').'|'.$nameKey;
        }

        return ($team ?: 'unknown').'|'.($operationalUserId ?: 'sin-clasificar');
    }

    public function normalizedUserKey(?string ...$names): string
    {
        return $this->normalizedCanonicalUserKey(...$names);
    }

    public function canonicalUserName(?string ...$names): string
    {
        $key = $this->normalizedCanonicalUserKey(...$names);

        return match ($key) {
            '' => 'Sin clasificar',
            'vanesa sanjuan' => 'Vanessa SanJuan',
            'callcenter fontellas' => 'Callcenter Fontellas',
            default => Str::of($key)->title()->toString(),
        };
    }

    public function isOverflow(
        ?string $origin,
        ?string $status,
        ?string $portal,
        ?string $team,
        ?string $pollValue = null,
        ?string $resultRaw = null,
    ): bool {
        if ($this->normalizeName($resultRaw) === 'abandoned') {
            return false;
        }

        if ($origin !== 'portal' || $status !== 'answered' || ! in_array($team, ['contact_center', 'customer_service'], true)) {
            return false;
        }

        if (! $this->isOverflowExcludedPortal($portal)) {
            return true;
        }

        return $this->isOverflowPollValue($pollValue);
    }

    public function overflowReason(
        ?string $origin,
        ?string $status,
        ?string $portal,
        ?string $team,
        ?string $pollValue = null,
        ?string $resultRaw = null,
    ): ?string {
        return $this->isOverflow($origin, $status, $portal, $team, $pollValue, $resultRaw)
            ? self::OVERFLOW_REASON_PORTAL_ATTENDED_BY_SUPPORT
            : null;
    }

    public function isOverflowPollValue(?string $pollValue): bool
    {
        $pollValue = trim((string) $pollValue);

        return $pollValue === '' || in_array($pollValue, ['1', '2'], true);
    }

    public function isOverflowExcludedPortal(?string $portal): bool
    {
        $portalKey = $this->normalizeName($portal);

        return in_array($portalKey, ['web', 'google maps'], true);
    }

    public function displayUserName(?string $operationalUserName, ?string $destinationAgentName, ?string $ownerName): string
    {
        foreach ([$operationalUserName, $destinationAgentName, $ownerName] as $name) {
            $clean = $this->cleanUserName($name);

            if ($clean !== '') {
                return $clean;
            }
        }

        return 'Sin clasificar';
    }

    public function cleanUserName(?string $name): string
    {
        return Str::of((string) $name)
            ->replaceMatches('/^\s*\[?\s*AG\d+\s*\]?\s*[-:]?\s*/iu', '')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    private function normalizedCanonicalUserKey(?string ...$names): string
    {
        foreach ($names as $name) {
            $key = $this->normalizeName($name);
            $compact = str_replace(' ', '', $key);

            if ($compact === '') {
                continue;
            }

            return match ($compact) {
                'vanessasanjuan', 'vanesasanjuan' => 'vanesa sanjuan',
                'callcenterfontellas' => 'callcenter fontellas',
                'vanesagerman' => 'vanesa german',
                'yuleidisgarcia' => 'yuleidis garcia',
                'joseignaciopalomo', 'josepalomocasas', 'joseignaciopalomocasas' => 'jose ignacio palomo',
                default => $key,
            };
        }

        return '';
    }
}
