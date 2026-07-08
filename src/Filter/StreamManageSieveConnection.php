<?php

declare(strict_types=1);

namespace Mailika\Filter;

use RuntimeException;

final class StreamManageSieveConnection implements ManageSieveConnectionInterface
{
    /** @var resource */
    private mixed $stream;

    /**
     * @param resource $stream
     */
    public function __construct(mixed $stream)
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('Invalid ManageSieve stream.');
        }

        $this->stream = $stream;
    }

    public function readLine(): string
    {
        $line = fgets($this->stream);
        if ($line === false) {
            throw new SievePublishException('ManageSieve server closed the connection.');
        }

        return $line;
    }

    public function readBytes(int $bytes): string
    {
        if ($bytes < 1) {
            return '';
        }

        $data = '';
        while (strlen($data) < $bytes) {
            $remainingBytes = $bytes - strlen($data);
            if ($remainingBytes < 1) {
                break;
            }

            $chunk = fread($this->stream, $remainingBytes);
            if ($chunk === false || $chunk === '') {
                throw new SievePublishException('ManageSieve literal response ended early.');
            }

            $data .= $chunk;
        }

        return $data;
    }

    public function write(string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($this->stream, substr($bytes, $offset));
            if ($written === false || $written < 1) {
                throw new SievePublishException('Unable to write ManageSieve command.');
            }

            $offset += $written;
        }
    }

    public function enableTls(): void
    {
        $enabled = stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($enabled !== true) {
            throw new SievePublishException('ManageSieve TLS negotiation failed.');
        }
    }

    public function close(): void
    {
        fclose($this->stream);
    }
}
