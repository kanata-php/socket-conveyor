<?php

namespace Conveyor\SubProtocols\Conveyor\ActionMiddlewares\Abstractions;

use Conveyor\SubProtocols\Conveyor\ActionMiddlewares\Interfaces\MiddlewareInterface;

/**
 * This is expected to behave as the League's Pipeline
 * package: https://pipeline.thephpleague.com .
 */
abstract class AbstractActionMiddleware implements MiddlewareInterface
{
}
