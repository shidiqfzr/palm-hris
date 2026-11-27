<?php

namespace App\Services\Bezetting;

use RuntimeException;

/** A Bezetting workbook that cannot be read; the message is safe to show to HR admins (no personal data). */
class BezettingReadException extends RuntimeException {}
