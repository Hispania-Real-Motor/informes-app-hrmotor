<?php

namespace App\Services\Reports\Leads;

final class InterestTechnicalOwnerClassifier
{
    private const IDS = [
        '0052X00000AP4U5QAL',
        '0057R00000AKkz0QAD',
        '0057R00000CQGZaQAP',
    ];

    private const NAMES = ['admin adesso', 'api user', 'carlos torres'];

    public static function isTechnical(?string $ownerId, ?string $ownerName): bool
    {
        return in_array((string) $ownerId, self::IDS, true)
            || in_array(mb_strtolower(trim((string) $ownerName)), self::NAMES, true);
    }
}
