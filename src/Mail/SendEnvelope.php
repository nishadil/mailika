<?php

declare(strict_types=1);

namespace Mailika\Mail;

use Mailika\Validation\Validator;

final readonly class SendEnvelope
{
    public bool $requiresSmtpUtf8;

    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     * @param list<array{path:string,name:string,mime:string}> $attachments
     */
    public function __construct(
        public array $to,
        public array $cc,
        public array $bcc,
        public string $subject,
        public string $plainBody,
        public string $htmlBody,
        public array $attachments = [],
        public ?string $identityEmail = null,
        public ?string $replyToMessageId = null,
        public ?string $replyToEmail = null,
        ?bool $requiresSmtpUtf8 = null,
    ) {
        $this->requiresSmtpUtf8 = $requiresSmtpUtf8 ?? $this->detectSmtpUtf8Requirement();
    }

    private function detectSmtpUtf8Requirement(): bool
    {
        foreach ($this->addresses() as $address) {
            if (Validator::emailRequiresSmtpUtf8($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function addresses(): array
    {
        $addresses = [...$this->to, ...$this->cc, ...$this->bcc];

        if ($this->identityEmail !== null && $this->identityEmail !== '') {
            $addresses[] = $this->identityEmail;
        }

        if ($this->replyToEmail !== null && $this->replyToEmail !== '') {
            $addresses[] = $this->replyToEmail;
        }

        return $addresses;
    }
}
