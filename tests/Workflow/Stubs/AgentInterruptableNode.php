<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Exceptions\ToolInterrupt;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\StopEvent;
use NeuronAI\Workflow\WorkflowState;

class AgentInterruptableNode extends Node
{
    /**
     * @throws WorkflowException
     */
    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        throw new ToolInterrupt([
            'toolName' => 'test'
        ]);

        return new StopEvent();
    }
}
