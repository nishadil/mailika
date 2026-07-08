<?php

declare(strict_types=1);

namespace Mailika\Mail;

use RuntimeException;
use Throwable;

final class SmtpDeliveryException extends RuntimeException
{
    public static function invalidSecurityMode(): self
    {
        return new self('SMTP security mode must be starttls, smtps, or none.');
    }

    public static function invalidEnvelopeData(): self
    {
        return new self('Message contains invalid sender, recipient, or header data.');
    }

    public static function transportPolicyViolation(): self
    {
        return new self('SMTP transport settings are not allowed in this deployment.');
    }

    public static function smtpUtf8Unsupported(Throwable $previous): self
    {
        return new self(
            'The SMTP server does not support internationalized mailbox names. '
                . 'Use ASCII sender and recipient local-parts or another SMTP server.',
            0,
            $previous,
        );
    }
}
