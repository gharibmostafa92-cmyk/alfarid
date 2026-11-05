<?php

declare(strict_types=1);

namespace App\Services\Ai\WhatsApp;

use Illuminate\Support\Carbon;

final class WhatsAppRenderer
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array{text:string,pending_action_id:?string,picker:?array<string,mixed>}>
     */
    public function render(string $text, array $blocks): array
    {
        if ($blocks === []) {
            $plain = $this->redact($text);

            return $plain === '' ? [] : [['text' => $plain, 'pending_action_id' => null, 'picker' => null]];
        }

        $messages = [];
        foreach ($blocks as $block) {
            $rendered = $this->block($block);
            if ($rendered !== null) {
                $messages[] = $rendered;
            }
        }
        if ($messages === []) {
            $plain = $this->redact($text);

            return $plain === '' ? [] : [['text' => $plain, 'pending_action_id' => null, 'picker' => null]];
        }

        return $messages;
    }

    public static function cardCode(string $actionId): string
    {
        return strtoupper(substr($actionId, -4));
    }

    /** @param array<string, mixed> $block
     * @return array{text:string,pending_action_id:?string,picker:?array<string,mixed>}|null
     */
    private function block(array $block): ?array
    {
        $type = (string) ($block['type'] ?? 'notice');

        return match ($type) {
            'confirm_action', 'permission_diff' => $this->confirm($block),
            'bulk_plan' => $this->bulk($block),
            'entity_picker', 'picker' => $this->picker($block),
            'table' => $this->table($block),
            'result' => $this->result($block),
            default => $this->notice($block),
        };
    }

    /** @param array<string, mixed> $block
     * @return array{text:string,pending_action_id:?string,picker:?array<string,mixed>}
     */
    private function confirm(array $block): array
    {
        $actionId = isset($block['action_id']) ? (string) $block['action_id'] : null;
        $risk = (string) ($block['risk'] ?? 'low');
        $mark = match ($risk) {
            'high' => '🔴',
            'medium' => '🟠',
            default => '🟢',
        };
        $riskLabel = match ($risk) {
            'high' => 'عالية',
            'medium' => 'متوسطة',
            default => 'منخفضة',
        };
        $lines = [$mark.' تأكيد إجراء — خطورة '.$riskLabel.($actionId ? '   [#'.self::cardCode($actionId).']' : '')];
        $lines[] = $this->redact((string) ($block['title'] ?? ''));
        $before = $this->pairs($block['before'] ?? null);
        $after = $this->pairs($block['after'] ?? null);
        if ($before !== '' || $after !== '') {
            $lines[] = 'قبل: '.($before !== '' ? $before : '—').' ← بعد: '.($after !== '' ? $after : '—');
        }
        foreach ((array) ($block['warnings'] ?? []) as $warning) {
            if (is_string($warning) && $warning !== '') {
                $lines[] = '⚠️ '.$this->redact($warning);
            }
        }
        if (isset($block['expires_at'])) {
            $lines[] = 'ينتهي '.Carbon::parse((string) $block['expires_at'])->timezone((string) config('app.business_timezone', 'Africa/Cairo'))->format('H:i');
        }
        if (! empty($block['typed_confirmation'])) {
            $hint = (string) ($block['typed_confirmation_hint'] ?? 'النص المطلوب');
            $lines[] = '↩️ رد على الرسالة دي بـ '.$hint.': '.$this->redact((string) $block['typed_confirmation']);
            $lines[] = '"تأكيد" لوحدها مش كفاية.';
        } else {
            $lines[] = '↩️ رد على الرسالة دي بـ *تأكيد* أو *إلغاء*';
        }

        return [
            'text' => $this->redact(implode("\n", array_filter($lines, fn ($line) => $line !== ''))),
            'pending_action_id' => $actionId,
            'picker' => null,
        ];
    }

    /** @param array<string, mixed> $block
     * @return array{text:string,pending_action_id:?string,picker:?array<string,mixed>}
     */
    private function bulk(array $block): array
    {
        $actionId = isset($block['action_id']) ? (string) $block['action_id'] : null;
        $lines = ['تنفيذ مجموعة'.($actionId ? ' [#'.self::cardCode($actionId).']' : '')];
        $ids = [];
        foreach (array_values((array) ($block['items'] ?? [])) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (string) ($item['action_id'] ?? $item['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
            $label = (string) ($item['title'] ?? $item['label'] ?? $item['message'] ?? ('عنصر '.($index + 1)));
            $lines[] = ($index + 1).'. '.$this->redact($label);
        }
        $lines[] = 'اكتب تنفيذ للكل أو تنفيذ 1،3';

        return [
            'text' => implode("\n", $lines),
            'pending_action_id' => $actionId,
            'picker' => ['kind' => 'bulk', 'ids' => $ids],
        ];
    }

    /** @param array<string, mixed> $block
     * @return array{text:string,pending_action_id:?string,picker:?array<string,mixed>}
     */
    private function picker(array $block): array
    {
        $lines = [$this->redact((string) ($block['purpose'] ?? 'اختار واحد'))];
        $options = [];
        foreach (array_values((array) ($block['options'] ?? [])) as $index => $option) {
            if (! is_array($option)) {
                continue;
            }
            $label = (string) ($option['label'] ?? $option['name'] ?? ('اختيار '.($index + 1)));
            $lines[] = ($index + 1).'. '.$this->redact($label);
            $options[] = [
                'entity' => (string) ($block['entity'] ?? $option['entity'] ?? ''),
                'id' => $option['id'] ?? null,
                'label' => $label,
            ];
        }
        $lines[] = 'رد برقم الاختيار';

        return [
            'text' => implode("\n", $lines),
            'pending_action_id' => null,
            'picker' => ['kind' => 'picker', 'options' => $options],
        ];
    }

    /** @param array<string, mixed> $block
     * @return array{text:string,pending_action_id:?string,picker:?array<string,mixed>}
     */
    private function table(array $block): array
    {
        $columns = array_values((array) ($block['columns'] ?? []));
        $rows = array_values((array) ($block['rows'] ?? []));
        $lines = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $parts = [];
            foreach (array_values($row) as $index => $cell) {
                $name = (string) ($columns[$index] ?? '');
                $value = is_scalar($cell) ? (string) $cell : '';
                $parts[] = ($name !== '' ? $name.': ' : '').$value;
            }
            $lines[] = $this->redact(implode(' · ', $parts));
        }
        if (count($rows) > 10) {
            $lines[] = 'الباقي على الويب';
        }

        return [
            'text' => $lines === [] ? 'مفيش صفوف.' : implode("\n", $lines),
            'pending_action_id' => null,
            'picker' => null,
        ];
    }

    /** @param array<string, mixed> $block
     * @return array{text:string,pending_action_id:?string,picker:?array<string,mixed>}
     */
    private function result(array $block): array
    {
        $ok = (bool) ($block['ok'] ?? false);
        $message = $this->redact((string) ($block['message'] ?? ''));
        $text = ($ok ? '✅ ' : '❌ ').$message;
        $undoId = $ok && ! empty($block['undo_available_until']) ? ($block['undo_action_id'] ?? null) : null;
        if (is_string($undoId)) {
            $text .= "\nرد على الرسالة دي بـ تراجع خلال 15 دقيقة";
        }

        return ['text' => $text, 'pending_action_id' => $undoId, 'picker' => null];
    }

    /** @param array<string, mixed> $block
     * @return array{text:string,pending_action_id:?string,picker:?array<string,mixed>}|null
     */
    private function notice(array $block): ?array
    {
        $message = $this->redact((string) ($block['message'] ?? $block['text'] ?? ''));
        if ($message === '') {
            return null;
        }

        return ['text' => $message, 'pending_action_id' => null, 'picker' => null];
    }

    private function pairs(mixed $data): string
    {
        if (! is_array($data) || $data === []) {
            return '';
        }
        $parts = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = implode('، ', array_map(fn ($item) => is_scalar($item) ? (string) $item : '', $value));
            }
            if (! is_scalar($value) || (string) $value === '') {
                continue;
            }
            $parts[] = (is_string($key) ? $key.': ' : '').$value;
        }

        return $this->redact(implode('، ', $parts));
    }

    public function redact(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '***', $text) ?? $text;
        $text = preg_replace('/(?:الباسورد المؤقت|(?:generated_password|password|كلمة المرور)\s*[:=])[^\n]*/iu', 'الباسورد مش بيتبعت على واتساب. افتحه من الويب.', $text) ?? $text;

        return trim($text);
    }
}
