<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance;

use App\Models\AiPendingAction;

/**
 * Bulk finance approvals move real money, so they are never reversible.
 * The bulk tool surfaces this reason in its preview instead of an undo flow.
 */
final class BulkUndoGuard
{
    public function allowsUndo(): bool
    {
        return false;
    }

    public function reason(): string
    {
        return 'التنفيذ الجماعي نهائي: الاعتمادات المنفذة حرّكت الأرصدة فعلاً ولا يمكن التراجع عنها.';
    }
}
