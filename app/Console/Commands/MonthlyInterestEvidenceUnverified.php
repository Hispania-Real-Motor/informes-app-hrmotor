<?php

namespace App\Console\Commands;

use RuntimeException;

/** Safe operational classification for completed but unverified monthly evidence. */
final class MonthlyInterestEvidenceUnverified extends RuntimeException {}
