<?php

declare(strict_types=1);

namespace App\Services\Ai;

final class AgentReply
{
    /** @param list<array<string,mixed>> $blocks */
    public function __construct(public string $text, public array $blocks = [], public ?int $messageId = null) {}

    public static function text(string $text): self
    {
        return new self($text);
    }

    /** @param list<array<string,mixed>> $blocks */
    public static function withBlocks(string $text, array $blocks): self
    {
        return new self($text, $blocks);
    }
}
