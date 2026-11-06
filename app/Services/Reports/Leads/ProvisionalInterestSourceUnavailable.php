<?php

namespace App\Services\Reports\Leads;

use RuntimeException;

/** Raised only when the fixed provisional journal contract is not trustworthy. */
final class ProvisionalInterestSourceUnavailable extends RuntimeException {}
