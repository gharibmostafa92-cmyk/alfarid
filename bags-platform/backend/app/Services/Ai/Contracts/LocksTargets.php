<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Models\AiPendingAction;

interface LocksTargets
{
    public function lockTargets(AiPendingAction $action): void;
}
