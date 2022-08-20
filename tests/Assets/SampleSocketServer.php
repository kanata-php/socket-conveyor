<?php

namespace Tests\Assets;

class SampleSocketServer
{
    public array $connections = [];

    public function __construct(
        protected $callback
    ) { }

    public function push(int $fd, string $data)
    {
        call_user_func($this->callback, $fd);
    }

    public function isEstablished(int $fd)
    {
        return in_array($fd, $this->connections);
    }
}