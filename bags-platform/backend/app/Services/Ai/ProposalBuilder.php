<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Shared\SettingService;
use Spatie\Permission\Models\Role;

final class ProposalBuilder
{
    private ?AiConversation $conversation = null;

    public function __construct(private SettingService $settings) {}

    public function setConversation(?AiConversation $conversation): void
    {
        $this->conversation = $conversation;
    }

    public function conversation(): ?AiConversation
    {
        return $this->conversation;
    }

    public function hashArgs(array $args): string
    {
        $c = $this->canonical($args);

        return hash_hmac('sha256', json_encode($c, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function canonical(mixed $v): mixed
    {
        if (is_array($v)) {
            $isList = array_is_list($v);
            $out = array_map(fn ($x) => $this->canonical($x), $v);
            if (! $isList) {
                ksort($out);
            }

            return $out;
        }

        return $v;
    }

    /**
     * @param  array<string,mixed>  $args
     * @param  array<string,mixed>  $expectedVersions
     * @param  list<string>  $warnings
     * @param  array<string,mixed>|null  $inverse
     * @param  array<string,mixed>  $extraPreview
     */
    public function make(
        User $actor,
        string $tool,
        array $args,
        string $risk = 'medium',
        string $title = '',
        mixed $before = null,
        mixed $after = null,
        array $warnings = [],
        array $expectedVersions = [],
        ?array $inverse = null,
        ?string $typedConfirmation = null,
        ?string $typedConfirmationHint = null,
        array $extraPreview = [],
        ?AiConversation $conv = null,
    ): ToolOutcome {
        $conversation = $conv ?? $this->conversation;
        if (! $conversation) {
            throw new \LogicException('ProposalBuilder requires an active AiConversation.');
        }

        $preview = array_merge([
            'title' => $title !== '' ? $title : $tool,
            'before' => $before,
            'after' => $after,
            'warnings' => $warnings,
        ], $extraPreview);

        if ($typedConfirmation !== null) {
            $preview['typed_confirmation'] = $typedConfirmation;
        }
        if ($typedConfirmationHint !== null) {
            $preview['typed_confirmation_hint'] = $typedConfirmationHint;
        }

        return $this->makeProposal($actor, $conversation, $tool, $args, $expectedVersions, $preview, $inverse, $risk);
    }

    public function makeProposal(
        User $actor,
        AiConversation $conv,
        string $tool,
        array $args,
        array $expected,
        array $preview,
        ?array $inverse,
        string $risk,
    ): ToolOutcome {
        if (in_array($tool, ['create_staff_user', 'assign_roles', 'set_user_status'], true)) {
            $names = array_merge($args['roles'] ?? [], $tool === 'assign_roles' ? ($args['add'] ?? []) : []);
            if (isset($args['user_id'])) {
                $names = array_merge($names, User::findOrFail($args['user_id'])->roles()->pluck('name')->all());
            }
            foreach (Role::where('guard_name', 'staff')->whereIn('name', $names)->get() as $role) {
                $expected['role:'.$role->id] = (int) $role->lock_version;
            }
        }
        $ttl = max(1, (int) $this->settings->get('ai_action_ttl_minutes', 10));
        $action = AiPendingAction::create([
            'conversation_id' => $conv->id,
            'user_id' => $actor->id,
            'tool' => $tool,
            'args' => $args,
            'expected_versions' => $expected,
            'preview' => $preview,
            'inverse' => $inverse,
            'args_hash' => $this->hashArgs($args),
            'risk' => $risk,
            'status' => 'pending',
            'expires_at' => now()->addMinutes($ttl),
        ]);

        [$typed, $typedHint] = self::resolveTypedConfirmation($tool, $args, $preview);

        $blockType = $preview['block_type'] ?? 'confirm_action';
        $block = [
            'type' => $blockType,
            'action_id' => $action->id,
            'tool' => $tool,
            'risk' => $risk,
            'title' => $preview['title'] ?? $tool,
            'before' => $preview['before'] ?? null,
            'after' => $preview['after'] ?? null,
            'warnings' => $preview['warnings'] ?? [],
            'expires_at' => $action->expires_at->toIso8601String(),
        ];

        foreach (['moved_id', 'rows', 'summary', 'top', 'blocked', 'stages', 'items', 'deep_link', 'stocktake_id', 'stocktake_number', 'recent_movements'] as $extraKey) {
            if (array_key_exists($extraKey, $preview)) {
                $block[$extraKey] = $preview[$extraKey];
            }
        }

        if ($risk === 'high' && $typed) {
            $block['typed_confirmation'] = $typed;
            if ($typedHint) {
                $block['typed_confirmation_hint'] = $typedHint;
            }
        }
        if (isset($preview['permission_diff'])) {
            $block = array_merge($block, ['type' => 'permission_diff'] + $block + $preview['permission_diff']);
        }

        return ToolOutcome::proposal($block, ['pending_action_id' => $action->id]);
    }

    /**
     * @param  array<string,mixed>  $args
     * @param  array<string,mixed>  $preview
     * @return array{0: ?string, 1: ?string}
     */
    public static function resolveTypedConfirmation(string $tool, array $args, array $preview = []): array
    {
        if (isset($preview['typed_confirmation']) && is_string($preview['typed_confirmation']) && $preview['typed_confirmation'] !== '') {
            return [$preview['typed_confirmation'], $preview['typed_confirmation_hint'] ?? null];
        }

        $typed = null;
        $typedHint = null;

        if ($tool === 'set_branch_status' && isset($args['branch_id'])) {
            $b = Branch::find($args['branch_id']);
            $typed = $b?->code;
        } elseif ($tool === 'delete_role' && isset($args['role_id'])) {
            $r = Role::find($args['role_id']);
            $typed = $r?->name;
        } elseif (in_array($tool, ['approve_dealer_application', 'reject_dealer_application', 'suspend_customer', 'set_credit_limit'], true) && isset($args['customer_id'])) {
            $phone = Customer::withTrashed()->find($args['customer_id'])?->phone;
            $typed = $phone ? substr((string) $phone, -4) : null;
            $typedHint = 'آخر 4 أرقام من موبايل التاجر';
        } elseif ($tool === 'adjust_customer_wallet' && isset($args['customer_id'])) {
            $phone = Customer::withTrashed()->find($args['customer_id'])?->phone;
            $typed = $phone ? substr((string) $phone, -4) : null;
            $typedHint = 'آخر 4 أرقام من موبايل العميل';
        } elseif (in_array($tool, ['set_category_status', 'delete_category'], true) && isset($args['category_id'])) {
            $cat = Category::withTrashed()->find($args['category_id']);
            $typed = $cat?->slug;
        } elseif (in_array($tool, ['set_product_status', 'delete_product'], true) && isset($args['product_id'])) {
            $prod = Product::withTrashed()->find($args['product_id']);
            $typed = $prod?->code;
        } elseif ($tool === 'delete_variant' && isset($args['variant_id'])) {
            $var = ProductVariant::withTrashed()->find($args['variant_id']);
            $typed = $var?->sku;
        } elseif ($tool === 'bulk_update_prices') {
            $typed = 'تنفيذ';
        }

        return [$typed, $typedHint];
    }
}
