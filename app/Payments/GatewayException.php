<?php

declare(strict_types=1);

namespace App\Payments;

/** A gateway refused a request or could not be reached. The message is safe to show staff; it never contains a secret. */
class GatewayException extends \RuntimeException
{
}
