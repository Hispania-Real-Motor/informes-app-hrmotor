<?php

namespace App\Services\Salesforce;

use RuntimeException;

class SalesforceInterestPersistenceConflict extends RuntimeException
{
    /** @param list<string> $salesforceIds */
    public function __construct(
        public readonly array $salesforceIds,
    ) {
        parent::__construct('Interest migration origin conflicts with another Salesforce Interest.');
    }
}
