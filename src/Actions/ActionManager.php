<?php

namespace Conveyor\Actions;

use Conveyor\Actions\Interfaces\ActionInterface;
use Conveyor\Exceptions\InvalidActionException;
use Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Exception;
use InvalidArgumentException;
use League\Pipeline\PipelineBuilder;
use League\Pipeline\PipelineInterface;
use OpenSwoole\WebSocket\Server;

class ActionManager
{
    protected array $handlerMap = [];
    protected array $pipelineMap = [];

    protected array $actions = [
        AddListenerAction::class,
        AssocUserToFdAction::class,
        BaseAction::class,
        BroadcastAction::class,
        ChannelConnectAction::class,
        ChannelDisconnectAction::class,
        FanoutAction::class,
    ];

    protected ?ActionInterface $currentAction = null;

    public function __construct(
        array $extraActions = [],
    ) {
        $this->actions = array_merge($this->actions, $extraActions);
    }

    /**
     * @throws Exception
     */
    public static function make(array $actions = [], bool $fresh = false): static
    {
        $manager = new static($actions);
        return $manager->startActions($fresh);
    }

    /**
     * @internal This method also leave the $parsedData property set to the instance.
     *
     * @param array $data
     * @param Server $server
     * @param int $fd
     * @param array<GenericPersistenceInterface> $persistence
     * @return ActionInterface
     *
     * @throws InvalidArgumentException|InvalidActionException|Exception
     */
    public function ingestData(
        array $data,
        Server $server,
        int $fd,
        array $persistence = [],
    ): ActionInterface {
        // @throws InvalidArgumentException|InvalidActionException
        $this->validateData($data);

        $this->currentAction = $this->getAction($data['action']);
        $this->currentAction->setFd($fd);
        $this->currentAction->setServer($server);

        foreach ($persistence as $persistenceInstance) {
            $this->setActionPersistence($persistenceInstance);
        }

        return $this->currentAction;
    }

    public function getCurrentAction(): ?ActionInterface
    {
        return $this->currentAction;
    }

    public function setCurrentAction(?ActionInterface $currentAction): void
    {
        $this->currentAction = $currentAction;
    }

    /**
     * @param ?array $data
     *
     * @return void
     *
     * @throws InvalidArgumentException|InvalidActionException
     */
    protected function validateData(?array $data): void
    {
        if (null === $data) {
            return; // base action
        }

        if (!isset($data['action'])) {
            throw new InvalidArgumentException('Missing action key in data!');
        }

        if (!$this->hasAction($data['action'])) {
            throw new InvalidActionException(
                'Invalid Action! This action (' . $data['action'] . ') is not set.'
            );
        }
    }

    /**
     * This method adds default actions to the manager.
     *
     * @param bool $fresh
     *
     * @return static
     *
     * @throws Exception
     */
    public function startActions(bool $fresh = false): static
    {
        foreach ($this->actions as $action) {
            if (is_string($action)) {
                $this->add(new $action);
                continue;
            } else if (is_array($action)) {
                $this->startActionWithMiddlewares($action);
                continue;
            }
            throw new Exception('Not valid action: ' . json_encode($action));
        }

        array_map(function($action) use ($fresh) {
            $action->setFresh($fresh);
        }, $this->handlerMap);

        return $this;
    }

    /**
     * Add a step for the current's action middleware.
     *
     * @param string $action
     * @param Callable $middleware
     *
     * @return static
     */
    public function middleware(string $action, callable $middleware) : static
    {
        if (!isset($this->pipelineMap[$action])) {
            $this->pipelineMap[$action] = [];
        }

        $this->pipelineMap[$action][] = $middleware;

        return $this;
    }

    /**
     * Check if actions already exists added.
     *
     * @param string $name
     * @return bool
     */
    public function hasAction(string $name): bool
    {
        return isset($this->handlerMap[$name]);
    }

    /**
     * Add an action to be handled. It returns a pipeline for
     * eventual middlewares to be added for each.
     *
     * @param ActionInterface $actionHandler
     *
     * @return static
     */
    public function add(ActionInterface $actionHandler) : static
    {
        $actionName = $actionHandler->getName();
        $this->handlerMap[$actionName] = $actionHandler;

        return $this;
    }

    /**
     * It removes an action from the Router.
     *
     * @param ActionInterface|string $action
     * @return static
     */
    public function remove(ActionInterface|string $action) : static
    {
        $actionName = is_string($action) ? $action : $action->getName();
        unset($this->handlerMap[$actionName]);

        return $this;
    }

    /**
     * Get an Action by name
     *
     * @param string $name
     * @return ActionInterface
     * @throws Exception
     */
    public function getAction(string $name): ActionInterface
    {
        if (!isset($this->handlerMap[$name])) {
            throw new Exception('Action not found: ' . $name);
        }

        return $this->handlerMap[$name];
    }

    /**
     * Prepare pipeline based on the current prepared handlers.
     *
     * @return PipelineInterface
     */
    public function getPipeline() : PipelineInterface
    {
        $pipelineBuilder = new PipelineBuilder;

        if (!isset($this->pipelineMap[$this->currentAction->getName()])) {
            return $pipelineBuilder->build();
        }

        foreach ($this->pipelineMap[$this->currentAction->getName()] as $middleware) {
            $pipelineBuilder->add($middleware);
        }

        return $pipelineBuilder->build();
    }

    /**
     * @param array $action
     * @return void
     *
     * @throws Exception
     */
    protected function startActionWithMiddlewares(array $action): void
    {
        if ($this->hasAction($action[0]::NAME)) {
            throw new Exception('Action already added!');
        }

        $actionInstance = new $action[0];
        $this->add($actionInstance);
        for ($i = 1; $i < count($action); $i++) {
            $this->middleware($actionInstance->getName(), $action[$i]);
        }
    }

    /**
     * Set persistence to the action instance.
     *
     * @param GenericPersistenceInterface $persistence
     * @return void
     */
    public function setActionPersistence(GenericPersistenceInterface $persistence): void
    {
        if (method_exists($this->currentAction, 'setPersistence')) {
            $this->currentAction->setPersistence($persistence);
        }
    }
}
