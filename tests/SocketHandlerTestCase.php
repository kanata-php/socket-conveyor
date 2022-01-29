<?php

namespace Tests;

use Conveyor\Actions\ChannelConnectionAction;
use Tests\Assets\SampleAction;
use PHPUnit\Framework\TestCase;
use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\SocketHandlers\SocketMessageRouter;
use Tests\Assets\SamplePersistence;

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
    protected function prepareSocketMessageRouter(?SamplePersistence $persistence = null)
    {
        $sampleAction = $this->getSampleAction();
        
        $socketRouter = new SocketMessageRouter($persistence);
        $resultOfAddMethod = $socketRouter->add($sampleAction);

        $this->assertInstanceOf(get_class($resultOfAddMethod), $socketRouter);

        $socketRouter->add(new ChannelConnectionAction);

        return [$socketRouter, $sampleAction];
    }
}
