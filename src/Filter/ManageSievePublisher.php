<?php

declare(strict_types=1);

namespace Mailika\Filter;

use Mailika\Auth\MailboxCredentials;
use Mailika\Config\Config;
use Mailika\Validation\Validator;
use Throwable;

final readonly class ManageSievePublisher implements SievePublisherInterface
{
    public function __construct(
        private Config $config,
        private ManageSieveClient $client = new ManageSieveClient(),
    ) {
    }

    public function configured(): bool
    {
        return $this->config->bool('sieve.enabled')
            && $this->host() !== ''
            && Validator::tcpPort($this->port());
    }

    public function scriptName(): string
    {
        $name = trim($this->config->string('sieve.script_name', 'mailika'));
        if ($name === '' || preg_match('/[\x00-\x1F\x7F\x80-\x9F]/', $name) === 1) {
            return 'mailika';
        }

        return mb_substr($name, 0, 128);
    }

    public function publish(MailboxCredentials $credentials, string $script): SievePublishResult
    {
        $host = $this->host();
        if (!$this->config->bool('sieve.enabled') || $host === '') {
            throw new SievePublishException('ManageSieve publishing is not configured.');
        }

        $port = $this->port();
        if (!Validator::tcpPort($port)) {
            throw new SievePublishException('ManageSieve port is invalid.');
        }

        if (!Validator::hostAllowed($host, $this->config->stringList('sieve.allowed_hosts'))) {
            throw new SievePublishException('ManageSieve host is not allowed.');
        }

        $tlsMode = $this->tlsMode();
        if ($this->config->bool('sieve.require_tls') && $tlsMode === 'none') {
            throw new SievePublishException('ManageSieve TLS is required.');
        }

        return $this->client->publish(
            $this->connect($host, $port, $tlsMode === 'tls'),
            $credentials,
            $this->scriptName(),
            $script,
            $tlsMode === 'starttls',
        );
    }

    private function host(): string
    {
        return strtolower(trim($this->config->string('sieve.host')));
    }

    private function port(): int
    {
        return $this->config->int('sieve.port', 4190);
    }

    private function tlsMode(): string
    {
        $mode = strtolower($this->config->string('sieve.tls', 'starttls'));
        return in_array($mode, ['starttls', 'tls', 'none'], true) ? $mode : 'starttls';
    }

    private function connect(string $host, int $port, bool $implicitTls): ManageSieveConnectionInterface
    {
        $scheme = $implicitTls ? 'tls' : 'tcp';
        $timeout = max(1, $this->config->int('sieve.timeout_seconds', 10));
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);

        $errorCode = 0;
        $errorMessage = '';
        $stream = @stream_socket_client(
            $scheme . '://' . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (!is_resource($stream)) {
            throw new SievePublishException('Unable to connect to ManageSieve server.');
        }

        try {
            stream_set_timeout($stream, $timeout);
            return new StreamManageSieveConnection($stream);
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }
}
