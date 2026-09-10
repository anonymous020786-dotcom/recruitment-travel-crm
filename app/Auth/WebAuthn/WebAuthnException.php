<?php

declare(strict_types=1);

namespace App\Auth\WebAuthn;

/**
 * A WebAuthn ceremony failed a verification step. The message is safe to show
 * to the user (it never contains cryptographic material).
 */
final class WebAuthnException extends \RuntimeException
{
}
