<?php

namespace Tests\Unit;

use Conveyor\Constants;
use Conveyor\Events\MessageReceivedEvent;
use Conveyor\Traits\HasHandlers;
use OpenSwoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tests\TestCase;

class TaskFinishDispatchTest extends TestCase
{
    public function testTaskDoesNotFinishWhenNoFinishListenerIsRegistered(): void
    {
        $server = $this->getMockBuilder(Server::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['finish'])
            ->getMock();
        $server->expects($this->never())->method('finish');

        $received = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            Constants::EVENT_MESSAGE_RECEIVED,
            static function (MessageReceivedEvent $event) use (&$received): void {
                $received = $event;
            },
        );
        $handler = $this->handler($dispatcher);

        $handler->handleTask($server, 1, 1, '{"fd":1,"data":"{}"}');

        $this->assertInstanceOf(MessageReceivedEvent::class, $received);
        $this->assertSame($server, $received->server);
        $this->assertSame('{"fd":1,"data":"{}"}', $received->data);
        $this->assertSame(1, $received->taskId);
        $this->assertSame(1, $received->reactorId);
    }

    public function testTaskFinishesWhenFinishListenerIsRegistered(): void
    {
        $server = $this->getMockBuilder(Server::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['finish'])
            ->getMock();
        $server->expects($this->once())
            ->method('finish')
            ->with('{"fd":1,"data":"{}"}')
            ->willReturn(true);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(Constants::EVENT_TASK_FINISHED, static function (): void {
            // Listener body intentionally empty; the handler only needs to
            // preserve finish() for callers that consume this event.
        });

        $handler = $this->handler($dispatcher);

        $handler->handleTask($server, 1, 1, '{"fd":1,"data":"{}"}');
    }

    private function handler(EventDispatcher $dispatcher): object
    {
        return new class ($dispatcher) {
            use HasHandlers {
                onTask as public handleTask;
            }

            protected EventDispatcher $eventDispatcher;

            public function __construct(EventDispatcher $eventDispatcher)
            {
                $this->eventDispatcher = $eventDispatcher;
            }
        };
    }
}
