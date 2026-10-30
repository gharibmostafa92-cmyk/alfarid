<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

class GeminiException extends RuntimeException
{
    /**
     * M7: توكنز الطلب الفاشل لازم تتسجّل في ميزانية المستخدم حتى لو الرد
     * ما اتعالش (finishReason غير STOP / رد نصي فاضي). اضبطها بالـ factory
     * مع رفع الاستثناء، وتقرأها الدائرة عبر usage().
     */
    public function __construct(string $message, public readonly ?int $providerStatus = null, private readonly ?array $usage = null)
    {
        // Provider bodies can contain prompts or credentials; never include them.
        parent::__construct($message);
    }

    public static function withUsage(string $message, array $usage, ?int $providerStatus = null): self
    {
        return new self($message, $providerStatus, $usage);
    }

    /** @return array{input_tokens:int, output_tokens:int, total_tokens:int}|null */
    public function usage(): ?array
    {
        return $this->usage;
    }
}
