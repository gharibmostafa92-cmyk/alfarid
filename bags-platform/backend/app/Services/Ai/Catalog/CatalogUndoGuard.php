<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use App\Models\AiPendingAction;
use App\Services\Ai\Contracts\UndoableTool;

final class CatalogUndoGuard
{
    /** Call after reading versions, then pass those versions into the service. */
    public static function assertUnchanged(UndoableTool $tool, AiPendingAction $action): void
    {
        $expected = $action->result['undo_state'] ?? null;
        abort_unless(is_string($expected) && hash_equals($expected, $tool->targetState($action)), 409, 'السجل اتعدل بعد التنفيذ؛ التراجع مش آمن.');
    }
}
