<?php

namespace Conveyor\Helpers;

use OpenSwoole\Http\Response;

class Http
{
    public static function json(
        Response $response,
        array $content,
        int $status = 200
    ): void {
        $response->status($status);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($content));
    }
}
