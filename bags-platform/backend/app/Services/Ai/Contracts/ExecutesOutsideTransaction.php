<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Models\AiPendingAction;

interface ExecutesOutsideTransaction
{
    public function executesOutsideTransaction(AiPendingAction $action): bool;
}
