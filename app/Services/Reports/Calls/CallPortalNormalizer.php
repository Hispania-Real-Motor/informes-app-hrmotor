<?php

namespace App\Services\Reports\Calls;

use Illuminate\Support\Str;

class CallPortalNormalizer
{
    public const COMMERCIAL_DIRECT = 'Comercial directo';

    public const UNCLASSIFIED = 'Sin clasificar';

    public function normalize(?string $value): array
    {
        $raw = $this->clean($value);

        if ($raw === null) {
            return $this->result($raw, self::COMMERCIAL_DIRECT, 'commercial_direct', 'commercial_direct');
        }

        $key = $this->key($raw);

        if ($key === 'llamada directa') {
            return $this->result($raw, self::COMMERCIAL_DIRECT, 'commercial_direct', 'commercial_direct');
        }

        return $this->result($raw, $this->portalForKey($key), 'portal', 'portales_field');
    }

    public function normalizeLeadPortal(?string $value): array
    {
        $raw = $this->clean($value);

        if ($raw === null) {
            return $this->result($raw, self::UNCLASSIFIED, 'portal', 'unclassified');
        }

        return $this->result($raw, $this->portalForKey($this->key($raw)), 'portal', 'lead');
    }

    public function normalizeInterestSource(?string $value): array
    {
        $normalized = $this->normalizeLeadPortal($value);

        return array_merge($normalized, [
            'source' => $normalized['portal'] === self::UNCLASSIFIED ? 'unclassified' : 'interest',
        ]);
    }

    public function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function portalForKey(string $key): string
    {
        if ($key === 'atencion al cliente') {
            return 'Atencion al cliente';
        }

        if ($key === 'web' || str_starts_with($key, 'web ')) {
            return 'Web';
        }

        if ($key === 'google maps' || str_starts_with($key, 'google maps ')) {
            return 'Google Maps';
        }

        if ($key === 'coches.net' || $key === 'cochesnet' || $key === 'coches net'
            || str_starts_with($key, 'coches.net ')
            || str_starts_with($key, 'cochesnet ')
            || str_starts_with($key, 'coches net ')) {
            return 'Coches.net';
        }

        if ($key === 'coches.com' || $key === 'cochescom' || $key === 'coches com'
            || str_starts_with($key, 'coches.com ')
            || str_starts_with($key, 'cochescom ')
            || str_starts_with($key, 'coches com ')) {
            return 'Coches.com';
        }

        if ($key === 'autocasion' || str_starts_with($key, 'autocasion ')) {
            return 'Autocasion';
        }

        if ($key === 'autoscout' || $key === 'autoscout24'
            || str_starts_with($key, 'autoscout ')
            || str_starts_with($key, 'autoscout24 ')) {
            return 'Autoscout';
        }

        if ($key === 'wallapop' || str_starts_with($key, 'wallapop ')) {
            return 'Wallapop';
        }

        return self::UNCLASSIFIED;
    }

    private function result(?string $raw, string $portal, string $origin, string $source): array
    {
        return [
            'raw' => $raw,
            'portal' => $portal,
            'origin' => $origin,
            'source' => $portal === self::UNCLASSIFIED ? 'unclassified' : $source,
        ];
    }

    private function key(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replace(['_', '/', '\\'], [' ', ' ', ' '])
            ->replaceMatches('/[()]+/', '')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}
