<?php

declare(strict_types=1);

namespace App\Services\Ai\WhatsApp;

/**
 * Marks the current confirm/undo as coming from WhatsApp.
 * Queue workers reuse the process, so the flag is always restored.
 */
final class WhatsAppActorContext
{
    private string $mode = 'off';

    public function mode(): string
    {
        return $this->mode;
    }

    public function run(string $mode, callable $callback): mixed
    {
        $previous = $this->mode;
        $this->mode = $mode;
        try {
            return $callback();
        } finally {
            $this->mode = $previous;
        }
    }
}
