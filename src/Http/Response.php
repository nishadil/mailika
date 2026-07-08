<?php

declare(strict_types=1);

namespace Mailika\Http;

use InvalidArgumentException;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $content = '',
        private int $status = 200,
        private array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
    ) {
        foreach ($this->headers as $name => $value) {
            $this->guardHeader($name, $value);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): self
    {
        return new self(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->guardHeader($name, $value);

        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $response = $this;
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->content;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        header_remove('X-Powered-By');
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->content;
    }

    private function guardHeader(string $name, string $value): void
    {
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid response header name.');
        }

        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Invalid response header value.');
        }
    }
}
