<?php

declare(strict_types=1);

namespace Payitgo\Exception;

/** A webhook request whose signature or timestamp did not check out. */
class SignatureVerificationException extends \RuntimeException
{
}
