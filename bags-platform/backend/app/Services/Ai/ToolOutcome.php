<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Encapsulates the execution result of an AI tool invocation.
 *
 * Normalizes tool data payloads, interactive UI picker blocks, human-in-the-loop proposals,
 * permission denials, and untrusted user input sanitization for model consumption.
 */
final class ToolOutcome
{
    /** @param array<string,mixed> $data @param array<string,mixed>|null $uiBlock */
    private function __construct(public string $type, public array $data = [], public ?array $uiBlock = null, public ?string $message = null) {}

    /**
     * Creates a standard informational data outcome.
     *
     * @param  array<string, mixed>  $data  Payload to return to the model.
     * @param  array<string, mixed>|null  $block  Optional interactive UI presentation block.
     * @param  string|null  $message  Optional merchant/staff summary message.
     */
    public static function data(array $data, ?array $block = null, ?string $message = null): self
    {
        return new self('data', $data, $block, $message);
    }

    /**
     * Formats a data outcome, truncating the payload if it exceeds the max character budget.
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>|null  $uiBlock
     */
    public static function present(array $data, ?array $uiBlock = null, ?string $message = null, int $maxChars = 16000): self
    {
        $max = max(1024, $maxChars);
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($encoded !== false && strlen($encoded) > $max) {
            return self::data([
                'truncated' => true,
                'message' => 'ضيّق البحث',
            ], $uiBlock, 'ضيّق البحث');
        }

        return self::data($data, $uiBlock, $message);
    }

    /**
     * Sanitizes user-provided text for LLM prompts, stripping control characters.
     *
     * @return array{untrusted_text: string}
     */
    public static function untrusted(?string $value, int $max = 300): array
    {
        $clean = mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value), 0, $max);

        return ['untrusted_text' => $clean];
    }

    /** Audit flag — validation/not-found denials must not flood the audit log. */
    public bool $auditable = true;

    public static function denied(string $message, bool $auditable = true): self
    {
        $outcome = new self('denied', [], null, $message);
        $outcome->auditable = $auditable;

        return $outcome;
    }

    public static function picker(array $block): self
    {
        return new self('picker', [], $block);
    }

    public static function proposal(array $block, array $data = []): self
    {
        return new self('proposal', $data, $block);
    }

    public static function result(bool $ok, string $message, array $data = [], ?array $block = null): self
    {
        return new self($ok ? 'result' : 'failed', $data, $block, $message);
    }

    public static function wrap(string $type, array $data = [], ?array $block = null, ?string $message = null): self
    {
        return new self($type, $data, $block, $message);
    }

    /**
     * Determines whether tool processing must immediately halt the orchestrator reasoning loop.
     *
     * Interactive UI pickers, confirmation proposals, and security denials require user interaction or abort execution.
     */
    public function haltsLoop(): bool
    {
        // Only auditable permission denials halt loop execution; validation errors allow LLM self-correction.
        return in_array($this->type, ['picker', 'proposal'], true)
            || ($this->type === 'denied' && $this->auditable);
    }

    /** @param array{name:string,args:array<string,mixed>} $call @return array{role:string,parts:list<array<string,mixed>>} */
    public function asFunctionResponse(array $call): array
    {
        $payload = $this->type === 'denied' ? ['denied' => true, 'message' => $this->message] : $this->data;

        $response = ['name' => $call['name'], 'response' => $payload];
        if (isset($call['id'])) {
            $response['id'] = $call['id'];
        }

        return ['role' => 'user', 'parts' => [['functionResponse' => $response]]];
    }
}
