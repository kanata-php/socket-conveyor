<?php

namespace Tests;

use Conveyor\Actions\BroadcastAction;
use Conveyor\Actions\ChannelConnectAction;
use Conveyor\Models\SocketChannelPersistenceTable;
use Conveyor\SocketHandlers\SocketMessageRouter;
use Error;
use Exception;
use Tests\Assets\NotValidMiddleware;
use Tests\Assets\SampleAction;
use Tests\Assets\SampleCustomException;
use Tests\Assets\SampleExceptionHandler;
use Tests\Assets\SampleMiddleware;
use Tests\Assets\SampleMiddleware2;
use Tests\Assets\SampleSocketServer;

class SocketMessageRouterTest extends SocketHandlerTestCase
{
    public function testCanAddHandlerForAction()
    {
        $actionManager = $this->router->getActionManager();

        $actionManager->add(new SampleAction);
        $this->assertInstanceOf(SocketMessageRouter::class, $this->router);
        $this->assertInstanceOf(
            SampleAction::class,
            $actionManager->getAction(SampleAction::ACTION_NAME),
        );
    }

    public function testCanRemoveHandlerForAction()
    {
        $actionManager = $this->router->getActionManager();

        $actionManager->add(new SampleAction);
        $this->assertInstanceOf(SocketMessageRouter::class, $this->router);
        $this->assertInstanceOf(
            SampleAction::class,
            $actionManager->getAction(SampleAction::ACTION_NAME),
        );
        $actionManager->remove(new SampleAction);
        $this->assertFalse($actionManager->hasAction(SampleAction::ACTION_NAME));
    }

    public function testCanExecuteAction()
    {
        $actionManager = $this->router->getActionManager();

        $actionManager->add(new SampleAction);
        $this->sendData(1, json_encode([
            'action' => SampleAction::ACTION_NAME,
        ]));
        $this->assertCount(1, $this->userKeys);
    }

    public function testCanBroadcastActionWithArrayData()
    {
        $verified = false;
        $this->callbackVerification = function ($data) use (&$verified) {
            $data = json_decode($data, true);
            $verified = isset($data['data']['field1'])
                && 'value1' === $data['data']['field1'];
        };
        $actionManager = $this->router->getActionManager();

        $actionManager->add(new BroadcastAction);
        $this->connectToChannel(1, 'test-channel');
        $this->connectToChannel(2, 'test-channel');
        $this->listenToAction(2, BroadcastAction::ACTION_NAME);
        $this->sendData(1, json_encode([
            'action' => BroadcastAction::ACTION_NAME,
            'data' => [
                'field1' => 'value1',
            ],
        ]));
        $this->assertCount(1, $this->userKeys);
        $this->assertTrue($verified);
    }

    public function testCanSetAndGetFdFromAction()
    {
        $fd = 1;

        $actionManager = $this->router->getActionManager();

        $actionManager->add(new SampleAction);

        $this->sendData(1, json_encode([
            'action' => SampleAction::ACTION_NAME,
        ]));

        $fdFromAction = $actionManager->getAction(SampleAction::ACTION_NAME)->getFd();

        $this->assertTrue($fd === $fdFromAction);
    }

    public function testCanSetAndGetServerFromAction()
    {
        $actionManager = $this->router->getActionManager();

        $actionManager->add(new SampleAction);

        $data = json_encode([
            'action' => SampleAction::ACTION_NAME,
        ]);
        ($this->router)($data, 1, $this->server);

        $action = $actionManager->getAction(SampleAction::ACTION_NAME);
        $serverFromAction = $action->getServer();

        $this->assertTrue($this->server === $serverFromAction);
    }

    public function testCanAddMiddlewareToPipelineOfHandlerAndExecute()
    {
        $actionManager = $this->router->getActionManager();

        $actionManager->add(new SampleAction);
        $actionManager->middleware(SampleAction::ACTION_NAME, new SampleMiddleware);

        $this->sendData(1, json_encode([
            'action' => SampleAction::ACTION_NAME,
            'token'  => 'valid-token',
        ]));

        $this->assertCount(1, $this->userKeys);
    }

    public function testCantAddActionAlreadyAddedThroughConstructor()
    {
        $this->expectException(Exception::class);

        new SocketMessageRouter(null, [
            [SampleAction::class, new SampleMiddleware],
            [SampleAction::class, function(){}],
        ]);
    }

    public function testCanAddMiddlewareThroughConstructor()
    {
        $socketRouter = new SocketMessageRouter(null, [
            [
                SampleAction::class,
                function ($p) {
                    $this->userKeys[$p->getFd()] = $p->getParsedData('action');
                    return $p;
                },
            ],
        ]);

        $data = json_encode([
            'action' => SampleAction::ACTION_NAME,
            'token'  => 'valid-token',
        ]);
        ($socketRouter)($data, 1, $this->server);

        $this->assertCount(1, $this->userKeys);
    }

    public function testCantAddInvalidMiddlewareThroughConstructor()
    {
        $this->expectError(Error::class);

        new SocketMessageRouter(null, [
            [SampleAction::class, new NotValidMiddleware]
        ]);
    }

    public function testCanAddMultipleMiddlewaresToPipelineOfHandlerAndExecute()
    {
        $actionManager = $this->router->getActionManager();

        $actionManager->add(new SampleAction);
        $actionManager->middleware(SampleAction::ACTION_NAME, new SampleMiddleware);
        $actionManager->middleware(SampleAction::ACTION_NAME, new SampleMiddleware2);

        $this->sendData(1, json_encode([
            'action' => SampleAction::ACTION_NAME,
            'token'  => 'valid-token',
            'second-verification'  => 'valid',
        ]));

        $this->assertCount(1, $this->userKeys);
    }

    public function testCanAddMiddlewareToPipelineOfHandlerAndFail()
    {
        $this->expectException(Exception::class);

        $this->router->getActionManager()->middleware(SampleAction::ACTION_NAME, new SampleMiddleware);

        $this->sendData(1, json_encode([
            'action' => SampleAction::ACTION_NAME,
            'token'  => 'invalid-token',
        ]));
    }

    public function testCanAddMultipleMiddlewaresToPipelineOfHandlerAndFailFirst()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid Token');

        $actionManager = $this->router->getActionManager();
        $actionManager->add(new SampleAction);
        $actionManager->middleware(SampleAction::ACTION_NAME, new SampleMiddleware);
        $actionManager->middleware(SampleAction::ACTION_NAME, new SampleMiddleware2);

        $this->sendData(1, json_encode([
            'action' => SampleAction::ACTION_NAME,
            'token'  => 'invalid-token',
            'second-verification'  => 'valid',
        ]));
    }

    public function testCanAddMultipleMiddlewaresToPipelineOfHandlerAndFailSecond()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid Second verification');

        $actionManager = $this->router->getActionManager();
        $actionManager->add(new SampleAction);
        $actionManager->middleware(SampleAction::ACTION_NAME, new SampleMiddleware);
        $actionManager->middleware(SampleAction::ACTION_NAME, new SampleMiddleware2);

        $this->sendData(1, json_encode([
            'action' => SampleAction::ACTION_NAME,
            'token'  => 'valid-token',
            'second-verification'  => 'invalid',
        ]));
    }

    public function testCanAddCustomExceptionMessageAfterMiddlewareException()
    {
        $exceptionHandler = new SampleExceptionHandler;

        $this->expectException(SampleCustomException::class);
        $this->expectExceptionMessage('This is a test custom exception!');

        $actionManager = $this->router->getActionManager();
        $actionManager->add(new SampleAction);
        $actionManager->middleware(SampleAction::ACTION_NAME, new SampleMiddleware);
        $this->router->addMiddlewareExceptionHandler($exceptionHandler);

        try {
            $this->sendData(1, json_encode([
                'action' => SampleAction::ACTION_NAME,
                'token'  => 'invalid-token',
            ]));
        } catch (Exception $e) {
            // let's verify the parameters that reached the exception handler.
            $this->assertTrue('Exception' === get_class($exceptionHandler->e), 'Not expected value at exception parameter.');
            $this->assertTrue(is_array($exceptionHandler->parsedData), 'Not expected value at data parameter.');
            $this->assertTrue(is_int($exceptionHandler->fd), 'Not expected value at fd parameter.');
            $this->assertTrue(SampleSocketServer::class === get_class($exceptionHandler->server), 'Not expected value at server parameter.');
            throw $e;
        }
    }

    public function testCantSendPlainText()
    {
        $this->sendData(1, 'some message');
        $this->assertCount(1, $this->userKeys);
    }

    public function testCanCallStaticMethod()
    {
        SocketMessageRouter::run('some message', 1, $this->server);
        $this->assertCount(1, $this->userKeys);
    }

    public function testCanCallRefreshPersistence()
    {
        $channelPersistence = new SocketChannelPersistenceTable;
        $conveyor = new SocketMessageRouter($channelPersistence);
        ($conveyor)(json_encode([
            'action' => ChannelConnectAction::ACTION_NAME,
            'channel' => 'test-channel',
        ]), 1, $this->server);

        $this->assertCount(1, $channelPersistence->getAllConnections());

        SocketMessageRouter::refresh($channelPersistence);

        $this->assertCount(0, $channelPersistence->getAllConnections());
    }
}
