<?php

namespace Tests;

use Conveyor\Actions\AddListenerAction;
use Conveyor\Actions\AssocUserToFdAction;
use Conveyor\Actions\ChannelConnectAction;
use Conveyor\SocketHandlers\Interfaces\GenericPersistenceInterface;
use Tests\Assets\SampleAction;
use PHPUnit\Framework\TestCase;
use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\SocketHandlers\SocketMessageRouter;
use Tests\Assets\SampleBroadcastAction;
use Tests\Assets\SampleBroadcastAction2;
use Tests\Assets\SamplePersistence;
use Tests\Assets\SampleReturnAction;

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
    protected function prepareSocketMessageRouter(null|array|GenericPersistenceInterface $persistence = null)
    {
        $sampleAction = $this->getSampleAction();

        $socketRouter = $this->getCleanSocketMessageRouter($persistence);
        $resultOfAddMethod = $socketRouter->add($sampleAction);

        $this->assertInstanceOf(get_class($resultOfAddMethod), $socketRouter);

        $socketRouter->add(new ChannelConnectAction);
        $socketRouter->add(new AddListenerAction);
        $socketRouter->add(new AssocUserToFdAction);
        $socketRouter->add(new SampleBroadcastAction);
        $socketRouter->add(new SampleBroadcastAction2);
        $socketRouter->add(new SampleAction);
        $socketRouter->add(new SampleReturnAction);

        return [$socketRouter, $sampleAction];
    }

    protected function getCleanSocketMessageRouter(null|array|GenericPersistenceInterface $persistence = null): SocketMessageRouter
    {
        return new SocketMessageRouter($persistence);
    }
}
