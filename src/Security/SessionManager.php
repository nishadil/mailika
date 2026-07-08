<?php

declare(strict_types=1);

namespace Mailika\Security;

use Mailika\Config\Config;
use RuntimeException;

final readonly class SessionManager
{
    public function __construct(private Config $config)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionPath = $this->config->rootPath('storage/sessions');
        if ($this->config->string('session.driver') === 'redis') {
            if (!extension_loaded('redis')) {
                throw new RuntimeException('Redis session driver requires the ext-redis PHP extension.');
            }

            ini_set('session.save_handler', 'redis');
            ini_set('session.save_path', $this->config->string('redis.session_dsn'));
        } elseif (is_dir($sessionPath)) {
            session_save_path($sessionPath);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', (string) $this->config->int('session.lifetime_seconds', 1800));

        session_name($this->config->string('session.name', 'mailika_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $this->config->bool('session.secure_cookie', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            $sessionName = session_name();
            setcookie(
                $sessionName === false ? 'mailika_session' : $sessionName,
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly'],
            );
        }

        session_destroy();
    }
}
