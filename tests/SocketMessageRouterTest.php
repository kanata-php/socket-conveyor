<?php

namespace Tests;

use stdClass;
use Exception;
use Tests\Assets\SampleAction;
use Tests\Assets\SampleMiddleware;
use Tests\Assets\SampleMiddleware2;
use Tests\Assets\SampleExceptionHandler;
use Tests\Assets\SampleCustomException;
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
        $result = ($socketRouter)($data, 1, new stdClass);

        $this->assertTrue($result);
    }

    public function testCanSetAndGetFdFromAction()
    {
        $fd = 1;
        
        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();

        $data = json_encode([
            'action' => $sampleAction->getName(),
        ]);
        $result = ($socketRouter)($data, $fd, new stdClass);

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
        $result = ($socketRouter)($data, 1, $server);

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
        $result = ($socketRouter)($data, 1, new stdClass);
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
        $result = ($socketRouter)($data, 1, new stdClass);
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
        $result = ($socketRouter)($data, 1, new stdClass);
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
        $result = ($socketRouter)($data, 1, new stdClass);
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
        $result = ($socketRouter)($data, 1, new stdClass);
    }

    public function testCanAddCustomExceptionMessageAfterMiddlewareException()
    {
        $this->expectException(SampleCustomException::class);
        $this->expectExceptionMessage('This is a test custom exception!');

        [$socketRouter, $sampleAction] = $this->prepareSocketMessageRouter();

        $socketRouter->middleware($sampleAction->getName(), new SampleMiddleware);

        $exceptionHandler = new SampleExceptionHandler;
        $socketRouter->addMiddlewareExceptionHandler($exceptionHandler);

        $data = json_encode([
            'action' => $sampleAction->getName(),
            'token'  => 'invalid-token',
        ]);

        try {
            $socketRouter->handle($data, 1, new stdClass);
        } catch (Exception $e) {
            // let's verify the parameters that reached the exception handler.
            $this->assertTrue('Exception' === get_class($exceptionHandler->e), 'Not expected value at exception parameter.');
            $this->assertTrue(is_array($exceptionHandler->parsedData), 'Not expected value at data parameter.');
            $this->assertTrue(is_int($exceptionHandler->fd), 'Not expected value at fd parameter.');
            $this->assertTrue('stdClass' === get_class($exceptionHandler->server), 'Not expected value at server parameter.');
            throw $e;
        }
    }
}
