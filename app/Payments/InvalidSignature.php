<?php

declare(strict_types=1);

namespace App\Payments;

/** A gateway message whose signature or hash did not verify: it must not be acted upon. */
final class InvalidSignature extends \RuntimeException
{
}
