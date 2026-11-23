<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** A workflow rule was violated (wrong turn, wrong status). The message is user-facing. */
final class DocumentStateException extends RuntimeException {}
