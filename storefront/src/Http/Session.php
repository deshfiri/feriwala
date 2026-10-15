<?php

declare(strict_types=1);

namespace Storefront\Http;

use SessionHandlerInterface;
use Storefront\Support\Db;
use Storefront\Support\Env;

/**
 * Sessions in MySQL, so the storefront holds no state on the web server and can
 * run on more than one.
 */
final class Session implements SessionHandlerInterface
{
    private static bool $started = false;

    public static function start(bool $secure): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_save_handler(new self, true);

        session_name('sf_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) self::lifetime());

        session_start();
        self::$started = true;
    }

    public static function lifetime(): int
    {
        return Env::int('SESSION_LIFETIME', 60 * 60 * 24 * 7);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    public static function pullFlash(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($messages) ? array_values($messages) : [];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function flashInput(array $input): void
    {
        $_SESSION['_old'] = $input;
    }

    /**
     * @return array<string, mixed>
     */
    public static function pullInput(): array
    {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);

        return is_array($old) ? $old : [];
    }

    // --- SessionHandlerInterface ---------------------------------------------------

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = Db::one('SELECT data FROM sessions WHERE id = ? AND last_activity > ?', [$id, time() - self::lifetime()]);

        return $row === null ? '' : (string) $row['data'];
    }

    public function write(string $id, string $data): bool
    {
        Db::exec(
            'INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)',
            [$id, $data, time()],
        );

        return true;
    }

    public function destroy(string $id): bool
    {
        Db::exec('DELETE FROM sessions WHERE id = ?', [$id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return Db::exec('DELETE FROM sessions WHERE last_activity < ?', [time() - $max_lifetime]);
    }
}
