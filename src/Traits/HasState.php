<?php

namespace Conveyor\Traits;

use OpenSwoole\Table;

trait HasState
{
    /**
     * @var ?Table
     */
    protected static ?Table $state = null;

    public static function startState(): void
    {
        if (self::$state !== null) {
            return;
        }

        self::$state = new Table(10024);
        self::$state->column('value', Table::TYPE_STRING, 40);
        self::$state->create();
    }

    /**
     * @param string $key
     * @param string $value
     * @return void
     */
    public function setState(string $key, string $value): void
    {
        self::$state->set($key, ['value' => $value]);
    }

    /**
     * @param string $key
     * @return string
     */
    public function getState(string $key): string
    {
        return self::$state->get($key, 'value');
    }

    public function hasState(string $key): bool
    {
        return self::$state->exists($key);
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        return self::$state->del($key);
    }
}
