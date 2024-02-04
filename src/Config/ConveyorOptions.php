<?php

namespace Conveyor\Config;

class ConveyorOptions
{
    public bool $trackProfile = false;
    public bool $usePresence = false;

    /**
     * @param array<array-key, mixed> $options
     * @return ConveyorOptions
     */
    public static function fromArray(array $options): ConveyorOptions
    {
        $instance = new self();

        foreach ($options as $key => $value) {
            if (property_exists($instance, $key)) {
                $instance->{$key} = $value;
            }
        }

        return $instance;
    }
}
