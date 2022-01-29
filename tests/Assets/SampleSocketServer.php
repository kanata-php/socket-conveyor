<?php

namespace Tests\Assets;

class SampleSocketServer
{
    public function __construct(
        protected $callback,
        protected string $key
    ) { }

    public function push(int $fd, string $data)
    {
        call_user_func($this->callback, $fd);
    }
}