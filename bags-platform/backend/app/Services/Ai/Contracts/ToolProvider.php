<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Models\User;
use App\Services\Ai\AgentTool;

interface ToolProvider
{
    /** @return array<string, AgentTool> */
    public function tools(): array;

    /**
     * Check whether this tool domain is currently enabled.
     */
    public function enabled(): bool;

    /**
     * Authorize entity inspection (returns null if entity type belongs to another domain).
     */
    public function canReadEntity(User $actor, string $type): ?bool;

    public function systemPromptSection(User $actor): ?string;

    public function domain(): string;

    public function usesGlobalAdminLock(): bool;
}
