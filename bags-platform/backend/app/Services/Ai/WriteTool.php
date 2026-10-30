<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiPendingAction;
use App\Models\User;

interface WriteTool extends AgentTool
{
    /** @param array<string,mixed> $args */
    public function prepare(User $actor, array $args): ToolOutcome;

    public function execute(User $actor, AiPendingAction $action): ToolOutcome;
}
