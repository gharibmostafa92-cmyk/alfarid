<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Models\User;
use App\Services\Ai\AgentTool;

interface ToolProvider
{
    /** @return array<string, AgentTool> */
    public function tools(): array;

    public function enabled(): bool;                 // يقرا الـ flag

    public function canReadEntity(User $actor, string $type): ?bool; // null = مش بتاعي

    public function systemPromptSection(User $actor): ?string;
}
