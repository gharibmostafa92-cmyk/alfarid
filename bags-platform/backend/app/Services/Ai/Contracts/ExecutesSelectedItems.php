<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Ai\ToolOutcome;

interface ExecutesSelectedItems
{
    /** @param list<string> $selectedItems */
    public function executeSelected(User $actor, AiPendingAction $action, array $selectedItems): ToolOutcome;
}
