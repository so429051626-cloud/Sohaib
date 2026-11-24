<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $data = [];
    private static bool $installed = false;

    public static function load(?array $config): void
    {
        self::$installed = is_array($config) && !empty($config['db']['name']);
        self::$data = array_replace_recursive([
            'app' => [
                'url'       => '',
                'key'       => '',
                'debug'     => false,
                'timezone'  => 'Asia/Gaza',
                'cron_key'  => '',
            ],
            'db' => [
                'host'    => 'localhost',
                'port'    => 3306,
                'name'    => '',
                'user'    => '',
                'pass'    => '',
                'charset' => 'utf8mb4',
            ],
        ], $config ?? []);
    }

    public static function installed(): bool
    {
        return self::$installed;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$data;
        foreach (explode('.', $key) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }
}
