<?php

namespace Tests;

use Tests\Assets\SampleAction;
use PHPUnit\Framework\TestCase;
use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\SocketHandlers\SocketMessageRouter;

class SocketHandlerTestCase extends TestCase
{
    /**
     * @return ActionInterface
     */
    protected function getSampleAction() : ActionInterface
    {
        return new SampleAction();
    }

    /**
     * @return array
     */
    protected function prepareSocketMessageRouter()
    {
        $sampleAction = $this->getSampleAction();
        $socketRouter = SocketMessageRouter::getInstance();
        $resultOfAddMethod = $socketRouter->add($sampleAction);

        $this->assertInstanceOf(get_class($resultOfAddMethod), $socketRouter);

        return [$socketRouter, $sampleAction];
    }
}