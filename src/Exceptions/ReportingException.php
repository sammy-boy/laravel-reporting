<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Exceptions;

use RuntimeException;

/**
 * Base type for every exception this package throws. Catch this to handle
 * "reporting failed for any reason" at a boundary.
 */
abstract class ReportingException extends RuntimeException {}
