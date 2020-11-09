<?php

namespace Tests;

use stdClass;
use Exception;
use League\Pipeline\Pipeline;
use Tests\Assets\SampleAction;
use Tests\SocketHandlerTestCase;
use Tests\Assets\SampleMiddleware;
use Tests\Assets\SampleMiddleware2;
use Conveyor\SocketHandlers\SocketMessageRouter;

class SocketMessageRouterTest extends SocketHandlerTestCase
{
    public function testCanAddHandlerForAction()
    {
        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();
        
        $this->assertInstanceOf(SocketMessageRouter::class, $socketRouter);
        $this->assertInstanceOf(SampleAction::class, $sampleAction);
    }

    public function testCanExecuteAction()
    {
        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();

        $data = json_encode([
            'action' => $sampleAction->getName(),
        ]);
        $result = ($socketRouter)($data);

        $this->assertTrue($result);
    }

    public function testCanSetAndGetFdFromAction()
    {
        $fd = 1;
        
        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();

        $data = json_encode([
            'action' => $sampleAction->getName(),
        ]);
        $result = ($socketRouter)($data, $fd);

        $this->assertTrue($result);
        $this->assertTrue($fd === $socketRouter->getAction($sampleAction->getName())->getFd());
    }

    public function testCanSetAndGetServerFromAction()
    {
        $server = new stdClass;
        $server->label = 'test-server';
        
        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();

        $data = json_encode([
            'action' => $sampleAction->getName(),
        ]);
        $result = ($socketRouter)($data, null, $server);

        $this->assertTrue($result);
        $this->assertTrue($server === $socketRouter->getAction($sampleAction->getName())->getServer());
    }

    public function testCanAddMiddlewareToPipelineOfHandlerAndExecute()
    {
        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();

        $socketRouter->middleware($sampleAction->getName(), new SampleMiddleware);

        $data = json_encode([
            'action' => $sampleAction->getName(),
            'token'  => 'valid-token',
        ]);
        $result = ($socketRouter)($data);
        $this->assertTrue($result);
    }

    public function testCanAddMultipleMiddlewaresToPipelineOfHandlerAndExecute()
    {
        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();

        $socketRouter->middleware($sampleAction->getName(), new SampleMiddleware);
        $socketRouter->middleware($sampleAction->getName(), new SampleMiddleware2);

        $data = json_encode([
            'action' => $sampleAction->getName(),
            'token'  => 'valid-token',
            'second-verification'  => 'valid',
        ]);
        $result = ($socketRouter)($data);
        $this->assertTrue($result);
    }

    public function testCanAddMiddlewareToPipelineOfHandlerAndFail()
    {
        $this->expectException(Exception::class);

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();

        $socketRouter->middleware($sampleAction->getName(), new SampleMiddleware);

        $data = json_encode([
            'action' => $sampleAction->getName(),
            'token'  => 'invalid-token',
        ]);
        $result = ($socketRouter)($data);
    }

    public function testCanAddMultipleMiddlewaresToPipelineOfHandlerAndFailFirst()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid Token');
        
        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();

        $socketRouter->middleware($sampleAction->getName(), new SampleMiddleware);
        $socketRouter->middleware($sampleAction->getName(), new SampleMiddleware2);

        $data = json_encode([
            'action' => $sampleAction->getName(),
            'token'  => 'invalid-token',
            'second-verification'  => 'valid',
        ]);
        $result = ($socketRouter)($data);
    }

    public function testCanAddMultipleMiddlewaresToPipelineOfHandlerAndFailSecond()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid Second verification');
        
        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();

        $socketRouter->middleware($sampleAction->getName(), new SampleMiddleware);
        $socketRouter->middleware($sampleAction->getName(), new SampleMiddleware2);

        $data = json_encode([
            'action' => $sampleAction->getName(),
            'token'  => 'valid-token',
            'second-verification'  => 'invalid',
        ]);
        $result = ($socketRouter)($data);
    }
}