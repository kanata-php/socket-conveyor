<?php

namespace Conveyor\SocketHandlers\Workflow;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;

class RouterWorkflow
{
    /**
     * @param array<string, callable> $listeners
     * @return Workflow
     */
    public static function newWorkflow(array $listeners = []): Workflow
    {
        $dispatcher = new EventDispatcher();

        $definitionBuilder = new DefinitionBuilder();
        $definition = $definitionBuilder
            ->setInitialPlaces(initialPlaces: 'started')
            ->addPlaces(places: [
                'started',
                'server_set',
                'fd_set',
                'persistence_set',
                'actions_added',
                'middleware_added',
                'action_prepared',
                'pipeline_prepared',
                'connections_cleared',
                'message_processed',
                'data_cleared',
            ])
            ->addTransition(new Transition(
                name: 'set_server',
                froms: 'started',
                tos: 'server_set',
            ))
            ->addTransition(new Transition(
                name: 'set_fd',
                froms: 'server_set',
                tos: 'fd_set',
            ))

            // apply persistence : begin
            ->addTransition(new Transition(
                name: 'apply_persistence',
                froms: 'fd_set',
                tos: 'persistence_set',
            ))
            ->addTransition(new Transition(
                name: 'apply_persistence',
                froms: 'actions_added',
                tos: 'persistence_set',
            ))
            ->addTransition(new Transition(
                name: 'apply_persistence',
                froms: 'middleware_added',
                tos: 'persistence_set',
            ))
            // apply persistence : end

            // add actions : begin
            ->addTransition(new Transition(
                name: 'add_actions',
                froms: 'fd_set',
                tos: 'actions_added',
            ))
            ->addTransition(new Transition(
                name: 'add_actions',
                froms: 'persistence_set',
                tos: 'actions_added',
            ))
            ->addTransition(new Transition(
                name: 'add_actions',
                froms: 'middleware_added',
                tos: 'actions_added',
            ))
            ->addTransition(new Transition(
                name: 'add_actions',
                froms: 'actions_added',
                tos: 'actions_added',
            ))
            // add actions : end

            // add middlewares : begin
            ->addTransition(new Transition(
                name: 'add_middleware',
                froms: 'fd_set',
                tos: 'middleware_added',
            ))
            ->addTransition(new Transition(
                name: 'add_middleware',
                froms: 'persistence_set',
                tos: 'middleware_added',
            ))
            ->addTransition(new Transition(
                name: 'add_middleware',
                froms: 'actions_added',
                tos: 'middleware_added',
            ))
            ->addTransition(new Transition(
                name: 'add_middleware',
                froms: 'middleware_added',
                tos: 'middleware_added',
            ))
            // add middlewares : end

            // prepare action : begin
            ->addTransition(new Transition(
                name: 'prepare_action',
                froms: 'persistence_set',
                tos: 'action_prepared',
            ))
            ->addTransition(new Transition(
                name: 'prepare_action',
                froms: 'actions_added',
                tos: 'action_prepared',
            ))
            ->addTransition(new Transition(
                name: 'prepare_action',
                froms: 'middleware_added',
                tos: 'action_prepared',
            ))
            // prepare action : end

            ->addTransition(new Transition(
                name: 'prepare_pipeline',
                froms: 'action_prepared',
                tos: 'pipeline_prepared',
            ))
            ->addTransition(new Transition(
                name: 'clear_connections',
                froms: 'pipeline_prepared',
                tos: 'connections_cleared',
            ))
            ->addTransition(new Transition(
                name: 'process_message',
                froms: 'connections_cleared',
                tos: 'message_processed',
            ))
            ->addTransition(new Transition(
                name: 'clear_data',
                froms: 'message_processed',
                tos: 'data_cleared',
            ))
            ->build();

        $marking = new MethodMarkingStore(
            singleState: true,
            property: 'state',
        );

        foreach ($listeners as $event => $listener) {
            $dispatcher->addListener($event, $listener);
        }

        return new Workflow(
            definition: $definition,
            markingStore: $marking,
            dispatcher: $dispatcher,
            name: 'conveyor-workflow',
        );
    }
}
