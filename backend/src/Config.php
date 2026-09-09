<?php

declare(strict_types=1);

namespace Trade;

final class Config
{
    private static array $data = [];

    public static function load(string $file): void
    {
        $config = require $file;
        if (!is_array($config)) {
            throw new \RuntimeException('Invalid Trade configuration file.');
        }
        self::$data = $config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $cursor = self::$data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    public static function require(string $key): mixed
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing configuration: {$key}");
        }
        return $value;
    }

    public static function installed(): bool
    {
        return self::$data !== [] && (bool) self::get('app.installed', false);
    }
}
