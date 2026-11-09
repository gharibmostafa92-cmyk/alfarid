<?php

declare(strict_types=1);

namespace App\Services\Ai\WhatsApp;

use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\AiWhatsappIdentity;
use App\Models\AiWhatsappInbound;
use App\Models\AiWhatsappLinkToken;
use App\Models\AiWhatsappOutbound;
use App\Models\User;
use App\Services\Ai\AgentOrchestrator;
use App\Services\Ai\AgentReply;
use App\Services\Ai\PendingActionService;
use App\Services\Shared\SettingService;
use App\Services\Auth\StaffAdministration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Processes incoming WhatsApp webhook messages for staff AI assistant operations.
 *
 * Enforces linking token authorization, optional PIN authentication, role permission verification,
 * rate limiting, and conversational orchestration.
 */
final class WhatsAppInboundProcessor
{
    public function __construct(
        private SettingService $settings,
        private WhatsAppGatewayClient $gateway,
        private AgentOrchestrator $orchestrator,
        private PendingActionService $pending,
        private WhatsAppRenderer $renderer,
        private WhatsAppActorContext $context,
    ) {}

    /**
     * Process an inbound WhatsApp webhook message through staff auth and conversational turn lifecycles.
     *
     * @param  AiWhatsappInbound  $inbound  Inbound audit log model.
     * @param  array<string, mixed>  $message  Decoded webhook payload.
     * @return string Execution outcome indicator ('done' or 'release').
     */
    public function process(AiWhatsappInbound $inbound, array $message): string
    {
        // Skip messages that have already been processed or superseded.
        if ($inbound->status !== 'queued') {
            return 'done';
        }
        $text = trim((string) ($message['text'] ?? ''));
        $remoteJid = (string) ($message['remote_jid'] ?? '');
        if ($remoteJid === '') {
            $this->finish($inbound, 'ignored', 'missing_jid', null, null);

            return 'done';
        }
        // Intercept account pairing token strings before evaluating regular conversational turns.
        if ($this->isLink($text)) {
            return $this->link($inbound, $message, $text);
        }

        $identity = $this->activeIdentity($message);
        if ($identity === null) {
            $this->finish($inbound, 'ignored', 'unknown_sender', null, null);

            return 'done';
        }
        $user = $identity->user;
        // Silently ignore messages if WhatsApp assistant is globally disabled, staff user is deactivated, or missing permissions.
        if ($user === null || ! $this->settings->getBool('ai_whatsapp_enabled', false) || ! $user->is_active || ! $this->allowsWhatsapp($user)) {
            $reason = ! $this->settings->getBool('ai_whatsapp_enabled', false) ? 'disabled' : (! $user?->is_active ? 'inactive' : 'no_permission');
            $this->finish($inbound, 'ignored', $reason, $identity->id, null);

            return 'done';
        }
        $identity->forceFill(['last_inbound_at' => now()])->save();

        // Reject commands until session is unlocked when PIN protection is enabled.
        if ($this->pinLocked($identity) && ! $this->isUnlock($text)) {
            $this->send($remoteJid, 'الجلسة مقفولة. اكتب فتح ثم الرقم السري.');
            $this->finish($inbound, 'processed', 'pin_locked', $identity->id, null);

            return 'done';
        }

        $command = $this->command($user, $identity, $message, $text);
        if ($command === 'release') {
            return 'release';
        }
        if ($command !== null) {
            $this->finish($inbound, 'processed', null, $identity->id, $command === 0 ? null : $command);

            return 'done';
        }

        $conversation = $this->conversation($user, false);
        $mediaReply = $this->prepareTurn($user, $conversation, $message, $text);
        if (is_string($mediaReply)) {
            $this->send($remoteJid, $mediaReply);
            $this->finish($inbound, 'processed', 'media', $identity->id, $conversation->id);

            return 'done';
        }

        $result = $this->freeText($user, $conversation, $remoteJid, $mediaReply['text'], $mediaReply['attachments'], null);
        // Clean up unconsumed transient attachments if turn was released due to concurrency locks or timeouts.
        if ($result === 'release') {
            foreach (AiAttachment::query()->whereIn('id', $mediaReply['attachments'])->whereNull('consumed_at')->get() as $attachment) {
                Storage::disk('ai_private')->delete($attachment->path);
                $attachment->delete();
            }

            return 'release';
        }
        $this->finish($inbound, 'processed', $result === 'rejected' ? 'rejected' : null, $identity->id, $conversation->id);

        return 'done';
    }

    /** @param array<string, mixed> $message */
    private function link(AiWhatsappInbound $inbound, array $message, string $text): string
    {
        $remoteJid = (string) $message['remote_jid'];
        if (! preg_match('/^ربط\s+([A-Za-z0-9]{4}-[A-Za-z0-9]{4})\s*$/u', $text, $matches)) {
            $this->finish($inbound, 'ignored', 'invalid_link', null, null);

            return 'done';
        }
        $hash = hash('sha256', strtoupper($matches[1]));
        $phone = $this->senderPhone($message);
        $lid = $this->lid($message);
        $pn = $this->pn($message);
        $identity = DB::transaction(fn () => $this->consumeLink($inbound, $hash, $phone, $lid, $pn));
        if ($identity !== null) {
            $this->send($remoteJid, 'تم ربط رقمك بحساب '.$identity->user?->name.' ✅');
        }

        return 'done';
    }

    private function consumeLink(AiWhatsappInbound $inbound, string $hash, ?string $phone, ?string $lid, ?string $pn): ?AiWhatsappIdentity
    {
        StaffAdministration::lock();
        $token = AiWhatsappLinkToken::query()->where('token_hash', $hash)->first();
        $identity = $token?->identity;
        if ($token === null || $identity?->status !== 'pending' || $token->consumed_at !== null || $token->expires_at->isPast()) {
            $this->finish($inbound, 'ignored', 'invalid_link', $identity?->id, null);

            return null;
        }
        if ($phone !== null ? $phone !== $identity->phone_e164 : $lid === null) {
            $this->finish($inbound, 'ignored', 'phone_mismatch', $identity->id, null);

            return null;
        }
        if (! $identity->user?->is_active || ! $this->allowsWhatsapp($identity->user)) {
            $this->finish($inbound, 'ignored', 'no_permission', $identity->id, null);

            return null;
        }
        $identity->forceFill([
            'wa_jid' => $pn,
            'wa_lid' => $lid,
            'status' => 'active',
            'verified_at' => now(),
        ])->save();
        $token->forceFill(['consumed_at' => now()])->save();
        $this->finish($inbound, 'processed', null, $identity->id, null);

        return $identity;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return int|string|null conversation id, or release
     */
    private function command(User $user, AiWhatsappIdentity $identity, array $message, string $text): int|string|null
    {
        $remoteJid = (string) $message['remote_jid'];
        $quoted = isset($message['quoted_id']) ? (string) $message['quoted_id'] : null;
        if ($text === 'مساعدة') {
            $this->send($remoteJid, "الأوامر:\nتأكيد / إلغاء / تراجع\nتنفيذ أو تنفيذ 1،3\nرقم الاختيار\nجديد / مساعدة\nقفل / فتح والرقم السري");

            return 0;
        }
        if ($text === 'جديد') {
            $conversation = $this->conversation($user, true);
            $this->send($remoteJid, 'بدأت محادثة جديدة.');

            return $conversation->id;
        }
        if ($text === 'قفل') {
            if (! $this->settings->getBool('ai_whatsapp_pin_required', false)) {
                $this->send($remoteJid, 'الرقم السري مش مفعّل.');

                return 0;
            }
            $identity->forceFill(['unlocked_until' => now()])->save();
            $this->send($remoteJid, 'اتقفلت الجلسة.');

            return 0;
        }
        if (preg_match('/^فتح\s+(\d{4,8})$/u', $text, $matches)) {
            $this->gateway->deleteMessage($remoteJid, (string) ($message['wa_message_id'] ?? ''));
            if (! $this->settings->getBool('ai_whatsapp_pin_required', false) || $identity->pin_hash === null) {
                $this->send($remoteJid, 'الرقم السري مش مفعّل.');

                return 0;
            }
            $limit = 'wa-pin:'.$identity->id;
            if (RateLimiter::tooManyAttempts($limit, 5)) {
                $this->send($remoteJid, 'محاولات كتير. جرّب تاني بعد 15 دقيقة.');

                return 0;
            }
            if (! Hash::check($matches[1], $identity->pin_hash)) {
                RateLimiter::hit($limit, 900);
                $this->send($remoteJid, 'الرقم السري غلط.');

                return 0;
            }
            $identity->forceFill(['unlocked_until' => now()->addMinutes(30)])->save();
            RateLimiter::clear($limit);
            $this->send($remoteJid, 'اتفتحت الجلسة لمدة 30 دقيقة.');

            return 0;
        }

        $conversation = $this->conversation($user, false);
        if (preg_match('/^(تأكيد|إلغاء|الغاء|تراجع)(?:\s+([A-Za-z0-9]{2,4}))?$/u', $text, $matches)) {
            $verb = $matches[1] === 'الغاء' ? 'إلغاء' : $matches[1];
            $statuses = $verb === 'تراجع' ? ['executed'] : ['pending'];
            $action = $this->resolveAction($conversation, $quoted, $matches[2] ?? null, $statuses);
            if (is_string($action)) {
                $this->send($remoteJid, $action);

                return $conversation->id;
            }
            $this->runAction($user, $remoteJid, $conversation, $verb, $action, null);

            return $conversation->id;
        }
        if (preg_match('/^تنفيذ(?:\s+(الكل|[\d،,\s]+))?$/u', $text, $matches)) {
            $action = $this->resolveAction($conversation, $quoted, null, ['pending']);
            if (is_string($action)) {
                $this->send($remoteJid, $action);

                return $conversation->id;
            }
            $selected = $this->bulkSelection($action, $matches[1] ?? null);
            if (is_string($selected)) {
                $this->send($remoteJid, $selected);

                return $conversation->id;
            }
            $this->runAction($user, $remoteJid, $conversation, 'تنفيذ', $action, 'تنفيذ', $selected);

            return $conversation->id;
        }
        $action = $this->quotedAction($quoted, $conversation);
        if ($text !== '' && $action?->status === 'pending' && $action->risk === 'high') {
            $this->runAction($user, $remoteJid, $conversation, 'تأكيد', $action, $text);

            return $conversation->id;
        }
        if (preg_match('/^\d{1,2}$/', $text)) {
            $selection = $this->selection($conversation, $quoted, (int) $text);
            if (is_string($selection)) {
                $this->send($remoteJid, $selection);

                return $conversation->id;
            }
            $result = $this->freeText($user, $conversation, $remoteJid, $text, [], $selection);
            if ($result === 'release') {
                return 'release';
            }

            return $conversation->id;
        }

        return null;
    }

    /** @param list<string> $statuses */
    private function resolveAction(AiConversation $conversation, ?string $quotedId, ?string $code, array $statuses): AiPendingAction|string
    {
        if ($quotedId !== null) {
            $quoted = $this->quotedAction($quotedId, $conversation);

            return $quoted !== null && in_array($quoted->status, $statuses, true) ? $quoted : 'الكارت المردود عليه مش متاح للإجراء ده.';
        }
        $pending = AiPendingAction::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('status', $statuses)
            ->when(in_array('pending', $statuses, true), fn ($query) => $query->where('expires_at', '>', now()))
            ->latest('id')
            ->get();
        if ($code !== null && $code !== '') {
            $matches = $pending->filter(fn (AiPendingAction $action): bool => str_ends_with(strtoupper((string) $action->id), strtoupper($code)))->values();
            if ($matches->count() === 1) {
                return $matches->first();
            }

            return $matches->count() === 0 ? 'مش لاقي الكارت ده.' : 'عندك أكتر من كارت، رد على الكارت نفسه.';
        }
        if ($pending->count() === 1) {
            return $pending->first();
        }

        return $pending->count() === 0 ? 'مفيش إجراء مستني التأكيد.' : 'عندك أكتر من كارت، رد على الكارت نفسه.';
    }

    private function quotedAction(?string $quotedId, AiConversation $conversation): ?AiPendingAction
    {
        if ($quotedId === null || $quotedId === '') {
            return null;
        }
        $outbound = AiWhatsappOutbound::query()->where('conversation_id', $conversation->id)->where('wa_message_id', $quotedId)->first();
        if ($outbound?->pending_action_id === null) {
            return null;
        }

        return AiPendingAction::query()->whereKey($outbound->pending_action_id)->where('conversation_id', $conversation->id)->first();
    }

    /** @return list<string>|string */
    private function bulkSelection(AiPendingAction $action, ?string $selector): array|string
    {
        $outbound = AiWhatsappOutbound::query()
            ->where('conversation_id', $action->conversation_id)
            ->where('pending_action_id', $action->id)
            ->where('picker->kind', 'bulk')
            ->latest('id')
            ->first();
        $ids = is_array($outbound?->picker['ids'] ?? null) ? array_values($outbound->picker['ids']) : [];
        if ($ids === []) {
            return 'مش لاقي قائمة التنفيذ.';
        }
        if ($selector === null || trim($selector) === '' || trim($selector) === 'الكل') {
            return $ids;
        }
        preg_match_all('/\d+/', $selector, $found);
        $picked = [];
        foreach ($found[0] as $number) {
            $index = (int) $number - 1;
            if (! isset($ids[$index])) {
                return 'رقم '.$number.' مش في القائمة.';
            }
            $picked[] = $ids[$index];
        }

        return $picked === [] ? 'اكتب تنفيذ للكل أو تنفيذ 1،3' : array_values(array_unique($picked));
    }

    /** @return array{entity:string,id:mixed,label:string}|string */
    private function selection(AiConversation $conversation, ?string $quotedId, int $number): array|string
    {
        $outbound = AiWhatsappOutbound::query()
            ->where('conversation_id', $conversation->id)
            ->when($quotedId !== null, fn ($query) => $query->where('wa_message_id', $quotedId))
            ->latest('id')
            ->first();
        $options = is_array($outbound?->picker['options'] ?? null) ? $outbound->picker['options'] : [];
        $option = ($outbound?->picker['kind'] ?? null) === 'picker' ? ($options[$number - 1] ?? null) : null;
        if (! is_array($option) || ! isset($option['id'], $option['entity'])) {
            return 'ابعت رقم من القائمة.';
        }

        return ['entity' => (string) $option['entity'], 'id' => $option['id'], 'label' => (string) ($option['label'] ?? '')];
    }

    /** @param list<string>|null $selected */
    private function runAction(User $user, string $remoteJid, AiConversation $conversation, string $verb, AiPendingAction $action, ?string $typed, ?array $selected = null): void
    {
        try {
            $outcome = match ($verb) {
                'إلغاء' => $this->pending->cancel($user, $action),
                'تراجع' => $this->context->run('undo', fn () => $this->pending->undo($user, $action)),
                default => $this->context->run('confirm', fn () => $this->pending->confirm($user, $action, $typed, $selected)),
            };
        } catch (HttpExceptionInterface $e) {
            $this->send($remoteJid, $e->getMessage() !== '' ? $e->getMessage() : 'الطلب مترفض.');

            return;
        }
        if ($verb === 'إلغاء') {
            $this->send($remoteJid, 'اتلغى الإجراء.');

            return;
        }
        $block = $outcome->uiBlock ?: ['type' => 'result', 'ok' => $outcome->type === 'result', 'message' => (string) ($outcome->message ?? '')];
        $this->deliver($conversation, $remoteJid, new AgentReply((string) ($outcome->message ?? ''), [$block]));
    }

    /**
     * @param  list<string>  $attachments
     * @param  array{entity:string,id:mixed,label?:string}|null  $selection
     */
    private function freeText(User $user, AiConversation $conversation, string $remoteJid, string $text, array $attachments, ?array $selection): string
    {
        try {
            $this->gateway->presence($remoteJid, 'composing');
            $reply = $this->orchestrator->handle($user, $conversation, [
                'text' => $text,
                'attachments' => $attachments,
                'selection' => $selection,
            ]);
            $this->deliver($conversation, $remoteJid, $reply);
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 423) {
                return 'release';
            }
            if ($e->getStatusCode() >= 500) {
                throw $e;
            }
            $this->send($remoteJid, $e->getMessage() !== '' ? $e->getMessage() : 'الطلب مترفض.');

            return 'rejected';
        } finally {
            $this->gateway->presence($remoteJid, 'paused');
        }

        return 'done';
    }

    private function deliver(AiConversation $conversation, string $remoteJid, AgentReply $reply): void
    {
        foreach ($this->renderer->render($reply->text, $reply->blocks) as $message) {
            $waId = $this->gateway->send($remoteJid, $message['text']);
            AiWhatsappOutbound::query()->create([
                'wa_message_id' => $waId,
                'conversation_id' => $conversation->id,
                'pending_action_id' => $message['pending_action_id'],
                'picker' => $message['picker'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{text:string,attachments:list<string>,selection:null}|string
     */
    private function prepareTurn(User $user, AiConversation $conversation, array $message, string $text): array|string
    {
        $media = is_array($message['media'] ?? null) ? $message['media'] : null;
        if ($media === null) {
            return ['text' => $text, 'attachments' => [], 'selection' => null];
        }
        $type = (string) ($media['type'] ?? '');
        if ($type !== 'image') {
            return 'ابعت نص أو صورة.';
        }
        try {
            $bytes = $this->gateway->downloadMedia((string) ($media['id'] ?? ''));
        } catch (WhatsAppGatewayException $e) {
            return $e->getCode() === 413 ? 'الصورة أكبر من 4 ميجا.' : 'الصورة مش متاحة، ابعتها تاني.';
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return 'ابعت نص أو صورة.';
        }
        if (strlen($bytes) > (int) config('services.gemini.max_image_bytes', 4 * 1024 * 1024)) {
            return 'الصورة أكبر من 4 ميجا.';
        }
        $quota = max(1, (int) config('services.gemini.max_pending_attachments_per_user', 40));
        $pending = AiAttachment::query()->where('user_id', $user->id)->whereNull('consumed_at')->count();
        if ($pending >= $quota) {
            return 'وصلت للحد الأقصى من المرفقات غير المستخدمة.';
        }
        $ulid = (string) Str::ulid();
        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $path = "ai-attachments/{$conversation->id}/{$ulid}.{$ext}";
        if (! Storage::disk('ai_private')->put($path, $bytes)) {
            throw new \RuntimeException('Could not store WhatsApp attachment.');
        }
        try {
            AiAttachment::query()->forceCreate([
                'id' => $ulid,
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'path' => $path,
                'mime' => $mime,
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('ai_private')->delete($path);
            throw $e;
        }

        return ['text' => $text, 'attachments' => [$ulid], 'selection' => null];
    }

    private function conversation(User $user, bool $forceNew): AiConversation
    {
        $hours = max(1, (int) $this->settings->get('ai_whatsapp_idle_rotate_hours', 12));
        $existing = AiConversation::query()
            ->where('user_id', $user->id)
            ->where('channel', 'whatsapp')
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->latest('id')
            ->first();
        $stale = ($existing?->last_message_at ?? $existing?->created_at)?->lt(now()->subHours($hours));
        if ($forceNew || $existing === null || $stale) {
            return AiConversation::query()->create([
                'user_id' => $user->id,
                'channel' => 'whatsapp',
                'model' => (string) config('services.gemini.model'),
            ]);
        }

        return $existing;
    }

    /** @param array<string, mixed> $message */
    private function activeIdentity(array $message): ?AiWhatsappIdentity
    {
        $keys = array_values(array_filter([(string) ($message['remote_jid'] ?? ''), (string) ($message['remote_jid_alt'] ?? '')]));
        if ($keys === []) {
            return null;
        }

        return AiWhatsappIdentity::query()
            ->where('status', 'active')
            ->whereNotNull('verified_at')
            ->where(function ($query) use ($keys): void {
                foreach ($keys as $key) {
                    $query->orWhere('wa_jid', $key)->orWhere('wa_lid', $key);
                }
            })
            ->first();
    }

    /** @param array<string, mixed> $message */
    private function senderPhone(array $message): ?string
    {
        return E164Phone::fromWhatsappJid(isset($message['remote_jid_alt']) ? (string) $message['remote_jid_alt'] : null)
            ?? E164Phone::fromWhatsappJid((string) ($message['remote_jid'] ?? ''));
    }

    /** @param array<string, mixed> $message */
    private function lid(array $message): ?string
    {
        foreach ([(string) ($message['remote_jid'] ?? ''), (string) ($message['remote_jid_alt'] ?? '')] as $jid) {
            if (str_ends_with($jid, '@lid')) {
                return $jid;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $message */
    private function pn(array $message): ?string
    {
        foreach ([(string) ($message['remote_jid'] ?? ''), (string) ($message['remote_jid_alt'] ?? '')] as $jid) {
            if (str_ends_with($jid, '@s.whatsapp.net')) {
                return $jid;
            }
        }

        return null;
    }

    private function pinLocked(AiWhatsappIdentity $identity): bool
    {
        if (! $this->settings->getBool('ai_whatsapp_pin_required', false)) {
            return false;
        }

        return $identity->unlocked_until === null || $identity->unlocked_until->isPast();
    }

    private function allowsWhatsapp(User $user): bool
    {
        try {
            return $user->can('ai_agent.use') && $user->can('ai_agent.whatsapp');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    private function isLink(string $text): bool
    {
        return str_starts_with($text, 'ربط');
    }

    private function isUnlock(string $text): bool
    {
        return (bool) preg_match('/^فتح\s+\d{4,8}$/u', $text);
    }

    private function send(string $jid, string $text): void
    {
        $text = $this->renderer->redact($text);
        if ($text !== '') {
            $this->gateway->send($jid, $text);
        }
    }

    private function finish(AiWhatsappInbound $inbound, string $status, ?string $reason, ?int $identityId, ?int $conversationId): void
    {
        $inbound->forceFill([
            'status' => $status,
            'ignore_reason' => $reason,
            'identity_id' => $identityId,
            'conversation_id' => $conversationId,
        ])->save();
    }
}
