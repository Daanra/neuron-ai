<?php

declare(strict_types=1);

namespace NeuronAI\Exceptions;

class ToolInterrupt extends AgentException
{
    public function __construct(
        protected array $data,
    ) {
        parent::__construct('Tool interrupted for human input');
    }

    public function getData(): array
    {
        return $this->data;
    }
}
