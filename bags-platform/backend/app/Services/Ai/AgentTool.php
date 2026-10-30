<?php

declare(strict_types=1);

namespace App\Services\Ai;

interface AgentTool
{
    public function name(): string;

    /** @return array<string,mixed> */
    public function declaration(): array;

    /** @return list<string> */
    public function permissions(): array;

    public function kind(): ToolKind;
}
