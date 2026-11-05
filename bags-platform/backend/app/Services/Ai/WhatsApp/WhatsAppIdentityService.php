<?php

declare(strict_types=1);

namespace App\Services\Ai\WhatsApp;

use App\Models\AiWhatsappIdentity;
use App\Models\AiWhatsappLinkToken;
use App\Models\User;
use App\Services\SettingService;
use App\Services\StaffAdministration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

final class WhatsAppIdentityService
{
    public function __construct(private SettingService $settings, private WhatsAppGatewayClient $gateway) {}

    public function register(User $actor, User $target, string $phone, ?string $pin = null): AiWhatsappIdentity
    {
        $phone = E164Phone::normalize($phone);

        return DB::transaction(function () use ($actor, $target, $phone, $pin): AiWhatsappIdentity {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['ai_agent.settings']);
            abort_if($target->is_system, 404);
            abort_unless($target->is_active, 422, 'الحساب مش نشط.');
            $identity = AiWhatsappIdentity::query()->updateOrCreate(
                ['user_id' => $target->id],
                [
                    'phone_e164' => $phone,
                    'wa_jid' => null,
                    'wa_lid' => null,
                    'status' => 'pending',
                    'verified_at' => null,
                    'pin_hash' => $pin !== null && $pin !== '' ? Hash::make($pin) : null,
                    'unlocked_until' => null,
                    'created_by' => $actor->id,
                ],
            );
            AiWhatsappLinkToken::query()->where('identity_id', $identity->id)->delete();
            StaffAdministration::audit($actor, 'ai_whatsapp.identity_saved', 'ai_whatsapp_identity', $identity->id, ['status'], [], ['status' => 'pending']);

            return $identity;
        });
    }

    /** @return array{url:string,expires_at:string,qr_data_url:?string} */
    public function issueLink(User $actor, AiWhatsappIdentity $identity): array
    {
        $issued = DB::transaction(function () use ($actor, $identity): array {
            StaffAdministration::lock();
            StaffAdministration::authorize($actor, ['ai_agent.settings']);
            $identity->refresh();
            abort_unless($identity->status === 'pending', 409, 'سجّل الرقم من الأول عشان تطلع رابط جديد.');
            AiWhatsappLinkToken::query()->where('identity_id', $identity->id)->delete();
            $plain = $this->plainToken();
            $minutes = max(1, (int) $this->settings->get('ai_whatsapp_link_token_ttl_minutes', 10));
            $expires = now()->addMinutes($minutes);
            AiWhatsappLinkToken::query()->create([
                'identity_id' => $identity->id,
                'token_hash' => hash('sha256', $plain),
                'expires_at' => $expires,
            ]);
            StaffAdministration::audit($actor, 'ai_whatsapp.link_issued', 'ai_whatsapp_identity', $identity->id, ['status'], [], ['status' => (string) $identity->status]);

            return ['plain' => $plain, 'expires' => $expires];
        });
        $status = $this->gateway->status();
        $bot = E164Phone::tryNormalize(isset($status['phone']) ? (string) $status['phone'] : null);
        abort_unless($bot !== null, 409, 'اربط رقم البوت الأول عشان نقدر نطلع باركود الموظف.');
        $url = 'https://wa.me/'.ltrim($bot, '+').'?text='.rawurlencode('ربط '.$issued['plain']);
        $qr = null;
        try {
            $encoded = $this->gateway->encodeQr($url);
            $qr = is_string($encoded['data_url'] ?? null) ? $encoded['data_url'] : null;
        } catch (WhatsAppGatewayException) {
            $qr = null;
        }

        return ['url' => $url, 'expires_at' => $issued['expires']->toIso8601String(), 'qr_data_url' => $qr];
    }

    public function revoke(User $target): void
    {
        AiWhatsappIdentity::query()
            ->where('user_id', $target->id)
            ->where('status', '!=', 'revoked')
            ->update(['status' => 'revoked', 'unlocked_until' => null, 'updated_at' => now()]);
    }

    public function revokeUsersOfRole(Role $role): void
    {
        User::role($role)->each(function (User $user): void {
            if (! $user->can('ai_agent.whatsapp')) {
                $this->revoke($user);
            }
        });
    }

    private function plainToken(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $chunk = function () use ($alphabet): string {
            $out = '';
            for ($i = 0; $i < 4; $i++) {
                $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            return $out;
        };

        return $chunk().'-'.$chunk();
    }
}
