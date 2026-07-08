#!/usr/bin/env php
<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

$host = option('host', '127.0.0.1');
$port = intOption('port', 4190);
$email = getenv('MAILIKA_TEST_SIEVE_EMAIL') ?: getenv('DOVECOT_TEST_EMAIL') ?: 'user@example.com';
$password = getenv('MAILIKA_TEST_SIEVE_PASSWORD') ?: getenv('DOVECOT_TEST_PASSWORD') ?: 'secret';

$server = @stream_socket_server(
    'tcp://' . $host . ':' . $port,
    $errorCode,
    $errorMessage,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
);

if (!is_resource($server)) {
    fwrite(STDERR, "Unable to start ManageSieve fixture on {$host}:{$port}: {$errorMessage}\n");
    exit(1);
}

stream_set_blocking($server, false);
$scripts = [];
$activeScript = null;
$running = true;

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

fwrite(STDOUT, "ManageSieve fixture listening on {$host}:{$port}\n");

while ($running) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    $connection = @stream_socket_accept($server, 1);
    if (!is_resource($connection)) {
        continue;
    }

    stream_set_timeout($connection, 10);
    handleConnection($connection, $email, $password, $scripts, $activeScript);
}

fclose($server);

/**
 * @param array<string, string> $scripts
 */
function handleConnection(
    mixed $connection,
    string $email,
    string $password,
    array &$scripts,
    ?string &$activeScript,
): void {
    writeAll($connection, "\"IMPLEMENTATION\" \"Mailika disposable ManageSieve fixture\"\r\n");
    writeAll($connection, "\"SASL\" \"PLAIN\"\r\n");
    writeAll($connection, "OK\r\n");

    while (($line = fgets($connection)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            continue;
        }

        if (preg_match('/^CAPABILITY$/i', $line) === 1) {
            writeAll($connection, "\"IMPLEMENTATION\" \"Mailika disposable ManageSieve fixture\"\r\n");
            writeAll($connection, "\"SASL\" \"PLAIN\"\r\n");
            writeAll($connection, "OK\r\n");
            continue;
        }

        if (preg_match('/^AUTHENTICATE\s+"PLAIN"\s+"([^"]*)"$/i', $line, $matches) === 1) {
            $decoded = base64_decode($matches[1], true);
            writeAll($connection, $decoded === "\0{$email}\0{$password}"
                ? "OK\r\n"
                : "NO \"Authentication failed\"\r\n");
            continue;
        }

        if (preg_match('/^GETSCRIPT\s+"([^"]+)"$/i', $line, $matches) === 1) {
            $name = unescapeQuoted($matches[1]);
            if (!array_key_exists($name, $scripts)) {
                writeAll($connection, "NO (NONEXISTENT) \"Script not found\"\r\n");
                continue;
            }

            writeAll($connection, '{' . strlen($scripts[$name]) . "}\r\n");
            writeAll($connection, $scripts[$name]);
            writeAll($connection, "OK\r\n");
            continue;
        }

        if (preg_match('/^PUTSCRIPT\s+"([^"]+)"\s+\{(\d+)\+\}$/i', $line, $matches) === 1) {
            $name = unescapeQuoted($matches[1]);
            $scripts[$name] = readBytes($connection, (int) $matches[2]);
            writeAll($connection, "OK\r\n");
            continue;
        }

        if (preg_match('/^SETACTIVE\s+"([^"]+)"$/i', $line, $matches) === 1) {
            $name = unescapeQuoted($matches[1]);
            if (!array_key_exists($name, $scripts)) {
                writeAll($connection, "NO (NONEXISTENT) \"Script not found\"\r\n");
                continue;
            }

            $activeScript = $name;
            writeAll($connection, "OK\r\n");
            continue;
        }

        if (preg_match('/^LOGOUT$/i', $line) === 1) {
            writeAll($connection, "OK\r\n");
            fclose($connection);
            return;
        }

        writeAll($connection, "NO \"Unsupported command\"\r\n");
    }

    fclose($connection);
}

function option(string $name, string $default): string
{
    global $argv;

    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            $value = substr($argument, strlen($name) + 3);
            return $value !== '' ? $value : $default;
        }
    }

    return $default;
}

function intOption(string $name, int $default): int
{
    $value = option($name, (string) $default);
    return is_numeric($value) ? (int) $value : $default;
}

function unescapeQuoted(string $value): string
{
    return str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
}

function readBytes(mixed $connection, int $bytes): string
{
    $data = '';
    while (strlen($data) < $bytes) {
        $remainingBytes = $bytes - strlen($data);
        if ($remainingBytes < 1) {
            break;
        }

        $chunk = fread($connection, $remainingBytes);
        if ($chunk === false || $chunk === '') {
            break;
        }

        $data .= $chunk;
    }

    return $data;
}

function writeAll(mixed $connection, string $data): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($connection, substr($data, $offset));
        if ($written === false || $written < 1) {
            return;
        }

        $offset += $written;
    }
}
