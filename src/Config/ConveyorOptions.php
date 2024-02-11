<?php

namespace Conveyor\Config;

class ConveyorOptions
{
    /**
     * @var array<array-key, mixed> $data
     */
    protected array $data = [];

    /**
     * @param array<array-key, mixed> $options
     * @return ConveyorOptions
     */
    public static function fromArray(array $options): ConveyorOptions
    {
        $instance = new self();

        foreach ($options as $key => $value) {
            $instance->{$key} = $value;
        }

        return $instance;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }
}
