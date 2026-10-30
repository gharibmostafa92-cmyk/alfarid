<?php

declare(strict_types=1);

namespace App\Services\Ai;

final class ToolOutcome
{
    /** @param array<string,mixed> $data @param array<string,mixed>|null $uiBlock */
    private function __construct(public string $type, public array $data = [], public ?array $uiBlock = null, public ?string $message = null) {}

    public static function data(array $data, ?array $block = null): self
    {
        return new self('data', $data, $block);
    }

    /** L11: denied audit flag — validation/not-found denials must not flood the audit log. */
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

    public static function wrap(string $type, array $data, ?array $block, ?string $message): self
    {
        return new self($type, $data, $block, $message);
    }

    public function haltsLoop(): bool
    {
        return in_array($this->type, ['picker', 'proposal', 'denied'], true);
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
