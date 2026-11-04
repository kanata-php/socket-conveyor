<?php

namespace Tests\Assets;

final class PusherDeterministicSources
{
    public static int $nanoseconds = 10000000000;
    public static array $randomValues = [];
    public static int $randomCalls = 0;
    public static bool $randomFails = false;
}

namespace Conveyor\SubProtocols\Pusher;

/** Test-only namespace seams, loaded only inside separate PHPUnit processes. */
function hrtime(bool $asNumber = false): int
{
    return \Tests\Assets\PusherDeterministicSources::$nanoseconds;
}

function random_int(int $minimum, int $maximum): int
{
    \Tests\Assets\PusherDeterministicSources::$randomCalls++;
    if (\Tests\Assets\PusherDeterministicSources::$randomFails) {
        throw new \RuntimeException('Test entropy unavailable');
    }
    return array_shift(\Tests\Assets\PusherDeterministicSources::$randomValues) ?? 42;
}
