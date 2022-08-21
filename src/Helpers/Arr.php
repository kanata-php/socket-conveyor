<?php

namespace Conveyor\Helpers;

class Arr
{
    /**
     * Get an item from an array using "dot" notation.
     * This is from Illuminate helper functions.
     *
     * @param array $array
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if (null === $key) return $array;
        if (isset($array[$key])) return $array[$key];

        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }
        return $array;
    }
}