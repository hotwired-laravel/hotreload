<?php

namespace Tests;

class TestRuns
{
    private static $runs = 0;

    public static function increment(): void
    {
        static::$runs++;
    }

    public static function runs(): int
    {
        return static::$runs;
    }

    public static function reset(): void
    {
        static::$runs = 0;
    }
}
