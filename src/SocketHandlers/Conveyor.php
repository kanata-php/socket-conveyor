<?php

namespace Conveyor\SocketHandlers;

use Conveyor\Actions\ActionManager;
use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\Actions\Traits\HasPersistence;
use Conveyor\Exceptions\InvalidActionException;
use Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\Persistence\WebSockets\AssociationsPersistence;
use Conveyor\Persistence\WebSockets\ChannelsPersistence;
use Conveyor\Persistence\WebSockets\ListenersPersistence;
use Conveyor\SocketHandlers\Workflow\MessageRouter;
use Conveyor\SocketHandlers\Workflow\RouterWorkflow;
use Exception;
use OpenSwoole\WebSocket\Server;
use Symfony\Component\Workflow\Event\EnterEvent;
use Symfony\Component\Workflow\Workflow;

class Conveyor
{
    use HasPersistence;

    protected Workflow $workflow;
    protected MessageRouter $messageRouter;

    /**
     * @param bool $fresh
     * @throws Exception
     */
    public function __construct(protected bool $fresh = false)
    {
        $this->workflow = RouterWorkflow::newWorkflow([
            // workflow.[workflow name].enter.[place name]
            'workflow.conveyor-workflow.enter.server_set' => [$this, 'handleSetServer'],
            'workflow.conveyor-workflow.enter.fd_set' => [$this, 'handleSetFd'],
            'workflow.conveyor-workflow.enter.persistence_set' => [$this, 'handleApplyPersistence'],
            'workflow.conveyor-workflow.enter.actions_added' => [$this, 'handleAddActions'],
            'workflow.conveyor-workflow.enter.middleware_added' => [$this, 'handleAddMiddlewareToAction'],
            'workflow.conveyor-workflow.enter.action_prepared' => [$this, 'handlePrepareAction'],
            'workflow.conveyor-workflow.enter.pipeline_prepared' => [$this, 'handlePreparePipeline'],
            'workflow.conveyor-workflow.enter.connections_cleared' => [$this, 'handleClearConnections'],
            'workflow.conveyor-workflow.enter.message_processed' => [$this, 'handleProcessMessage'],
            'workflow.conveyor-workflow.enter.data_cleared' => [$this, 'handleClearData'],
        ]);
        $this->messageRouter = new MessageRouter();
        $this->workflow->getMarking($this->messageRouter);
    }

    /**
     * @param bool $fresh
     * @return Conveyor
     * @throws Exception
     */
    public static function init(bool $fresh = false): Conveyor
    {
        return new self($fresh);
    }

    /**
     * This is used to refresh persistence.
     *
     * @param array<GenericPersistenceInterface> $persistenceList
     * @return void
     * @throws Exception
     */
    public static function refresh(array $persistenceList = []): void
    {
        foreach ($persistenceList as $persistence) {
            $persistence->refresh(true);
        }
    }

    public function getMessageRouter(): MessageRouter
    {
        return $this->messageRouter;
    }

    /**
     * @return ActionManager
     */
    public function getActionManager(): ActionManager
    {
        return $this->messageRouter->actionManager;
    }

    // ----------------------------------
    // Workflow Common
    // ----------------------------------

    private function getPreviousTransition(string $transition): string
    {
        $transitions = $this->workflow->getDefinition()->getTransitions();
        $previous = 'started';
        for ($i = 0; $i < count($transitions); $i++) {
            if ($transitions[$i]->getName() === $transition) {
                break;
            }
            $previous = $transitions[$i]->getName();
        }

        return $previous;
    }

    /**
     * @param string $transition
     * @param array<array-key, mixed> $context
     * @return static
     * @throws Exception
     */
    public function applyTransition(
        string $transition,
        array $context = [],
    ): static {
        if (!$this->workflow->can($this->messageRouter, $transition)) {
            throw new Exception(
                'Can\'t ' . $transition . ' at '
                . $this->messageRouter->getState()
                . '! (must ' . $this->getPreviousTransition($transition)
                . ' first)',
            );
        }

        $this->workflow->apply(
            subject: $this->messageRouter,
            transitionName: $transition,
            context: $context,
        );

        return $this;
    }

    // ----------------------------------
    // Step 1 : Set Server
    // ----------------------------------

    public function server(Server $server): static
    {
        return $this->applyTransition(
            transition: 'set_server',
            context: [
                'server' => $server,
            ],
        );
    }

    /**
     * @throws Exception
     */
    public function handleSetServer(EnterEvent $event): void
    {
        /** @var array<array-key, mixed> $context */
        $context = $event->getContext();

        /** @var MessageRouter $messageRouter */
        $messageRouter = $event->getSubject();

        if (!isset($context['server'])) {
            throw new Exception(
                'Missing server in context at "'
                . $event->getTransition()->getName() . '" transition!',
            );
        }

        $messageRouter->server = $context['server'];
    }

    // ----------------------------------
    // Step 2 : Set Fd
    // ----------------------------------

    public function fd(int $fd): static
    {
        return $this->applyTransition(
            transition: 'set_fd',
            context: [
                'fd' => $fd,
            ],
        );
    }

    public function handleSetFd(EnterEvent $event): void
    {
        /** @var array<array-key, mixed> $context */
        $context = $event->getContext();

        /** @var MessageRouter $messageRouter */
        $messageRouter = $event->getSubject();

        if (!isset($context['fd'])) {
            throw new Exception('Missing fd in context at "' . $event->getTransition()->getName() . '" transition!');
        }

        $messageRouter->fd = $context['fd'];
    }

    // ----------------------------------
    // Step 3: Apply Persistence
    // ----------------------------------

    /**
     * @param array<GenericPersistenceInterface> $persistence
     *
     * @return static
     * @throws Exception
     */
    public function persistence(array $persistence = []): static
    {
        return $this->applyTransition(
            transition: 'apply_persistence',
            context: [
                'persistence' => $persistence,
            ],
        );
    }

    public function handleApplyPersistence(EnterEvent $event): static
    {
        /** @var array<array-key, mixed> $context */
        $context = $event->getContext();

        /** @var MessageRouter $messageRouter */
        $messageRouter = $event->getSubject();

        if (!isset($context['persistence'])) {
            throw new Exception(
                'Missing persistence in context at "'
                . $event->getTransition()->getName() . '" transition!',
            );
        }

        if (empty($context['persistence'])) {
            $context['persistence'] = [
                new ChannelsPersistence(),
                new ListenersPersistence(),
                new AssociationsPersistence(),
            ];
        }

        foreach ($context['persistence'] as $item) {
            $messageRouter->setPersistence($item);
        }

        return $this;
    }

    // ----------------------------------
    // Extra Alternative Step : actions
    // ----------------------------------

    // add actions

    /**
     * @param array<ActionInterface> $actions
     * @return static
     * @throws Exception
     */
    public function addActions(array $actions = []): static
    {
        return $this->applyTransition(
            transition: 'add_actions',
            context: [
                'actions' => $actions,
            ],
        );
    }

    /**
     * @param EnterEvent $event
     * @return static
     * @throws Exception
     */
    public function handleAddActions(EnterEvent $event): static
    {
        /** @var array<array-key, mixed> $context */
        $context = $event->getContext();

        /** @var MessageRouter $messageRouter */
        $messageRouter = $event->getSubject();

        if (!isset($context['actions'])) {
            throw new Exception(
                'Missing actions in context at "'
                . $event->getTransition()->getName() . '" transition!',
            );
        }

        foreach ($context['actions'] as $action) {
            $messageRouter->actionManager->add($action);
        }

        return $this;
    }

    // add middleware

    /**
     * @param string $actionName
     * @param callable $middleware
     * @return static
     */
    public function addMiddlewareToAction(string $actionName, callable $middleware): static
    {
        return $this->applyTransition(
            transition: 'add_middleware',
            context: [
                'actionName' => $actionName,
                'middleware' => $middleware,
            ],
        );
    }

    /**
     * @param EnterEvent $event
     * @return static
     */
    public function handleAddMiddlewareToAction(EnterEvent $event): static
    {
        /** @var array<array-key, mixed> $context */
        $context = $event->getContext();

        /** @var MessageRouter $messageRouter */
        $messageRouter = $event->getSubject();

        if (!isset($context['actionName'])) {
            throw new Exception(
                'Missing action name in context at "'
                . $event->getTransition()->getName() . '" transition!',
            );
        }

        if (!isset($context['middleware'])) {
            throw new Exception(
                'Missing middleware in context at "'
                . $event->getTransition()->getName() . '" transition!',
            );
        }

        $messageRouter->actionManager->middleware($context['actionName'], $context['middleware']);

        return $this;
    }

    // ----------------------------------
    // Step 4 : prepare actions
    // ----------------------------------

    /**
     * @param string $data
     * @return mixed
     * @throws Exception
     */
    public function run(string $data): mixed
    {
        $this->applyTransition(
            transition: 'prepare_action',
            context: [
                'data' => $data,
            ],
        );

        $this->applyTransition('prepare_pipeline'); // step 5
        $this->applyTransition('clear_connections'); // step 6
        $this->applyTransition('process_message'); // step 7

        return $this;
    }

    /**
     * @throws InvalidActionException
     * @throws Exception
     */
    public function handlePrepareAction(EnterEvent $event): static
    {
        /** @var array<array-key, mixed> $context */
        $context = $event->getContext();

        /** @var MessageRouter $messageRouter */
        $messageRouter = $event->getSubject();

        if (!isset($context['data'])) {
            throw new Exception('Missing data in context at "' . $event->getTransition()->getName() . '" transition!');
        }

        $messageRouter->ingestData($context['data']);

        // @throws InvalidArgumentException|InvalidActionException
        $messageRouter->actionManager->ingestData(
            data: $messageRouter->data,
            server: $messageRouter->server,
            fd: $messageRouter->fd,
            persistence: array_filter([
                $messageRouter->channelPersistence,
                $messageRouter->listenerPersistence,
                $messageRouter->userAssocPersistence,
            ]),
        );

        return $this;
    }

    // ----------------------------------
    // Step 5 : prepare pipeline
    // ----------------------------------

    /**
     * @throws Exception
     */
    public function handlePreparePipeline(EnterEvent $event): static
    {
        /** @var MessageRouter $messageRouter */
        $messageRouter = $event->getSubject();

        $messageRouter->pipeline = $messageRouter->actionManager->getPipeline();

        return $this;
    }

    // ----------------------------------
    // Step 6 : clear connections
    // ----------------------------------

    /**
     * @throws Exception
     */
    public function handleClearConnections(EnterEvent $event): static
    {
        /** @var MessageRouter $messageRouter */
        $messageRouter = $event->getSubject();

        $messageRouter->closeConnections();

        return $this;
    }

    // ----------------------------------
    // Step 7 : process message
    // ----------------------------------

    /**
     * @return mixed
     *
     * @throws Exception
     */
    public function handleProcessMessage(EnterEvent $event): mixed
    {
        /** @var MessageRouter $messageRouter */
        $messageRouter = $event->getSubject();

        // @throws Exception
        $messageRouter->pipeline->process($messageRouter->data);

        // @throws Exception
        return $messageRouter->processMessage();
    }

    // ----------------------------------
    // Step 8 : clear data
    // ----------------------------------

    public function clear(?callable $callback = null): static
    {
        return $this->applyTransition(
            transition: 'clear_data',
            context: [
                'callback' => $callback,
            ],
        );
    }

    /**
     * @return static
     *
     * @throws Exception
     */
    public function handleClearData(EnterEvent $event): static
    {
        /** @var MessageRouter $messageRouter */
        $messageRouter = $event->getSubject();

        $callback = $event->getContext()['callback'] ?? null;

        $messageRouter->data = [];
        $messageRouter->actionManager->setCurrentAction(null);
        if (is_callable($callback)) {
            $callback();
        }

        return $this;
    }
}
