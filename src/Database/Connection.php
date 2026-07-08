<?php

declare(strict_types=1);

namespace Mailika\Database;

use Mailika\Config\Config;
use PDO;

final readonly class Connection
{
    public function __construct(private Config $config)
    {
    }

    public function pdo(): PDO
    {
        return new PDO(
            $this->config->string('database.dsn'),
            $this->config->string('database.user'),
            $this->config->string('database.password'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }

    public function driverAvailable(): bool
    {
        $driver = strstr($this->config->string('database.dsn'), ':', true);
        if (!is_string($driver) || $driver === '') {
            return false;
        }

        return in_array(strtolower($driver), PDO::getAvailableDrivers(), true);
    }
}
