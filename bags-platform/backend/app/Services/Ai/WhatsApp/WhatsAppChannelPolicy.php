<?php

declare(strict_types=1);

namespace App\Services\Ai\WhatsApp;

use App\Services\Ai\ToolKind;
use App\Services\Shared\SettingService;

/**
 * WhatsApp channel security policies: restricts high-risk actions, prevents unauthorized writes,
 * and enforces PIN-verified exceptions for wholesale payment notice approvals.
 */
final class WhatsAppChannelPolicy
{
    /** @var list<string> */
    public const DISABLED_BY_DEFAULT = [
        'create_staff_user',
        'create_role',
        'update_role_permissions',
        'delete_role',
        'assign_roles',
        'revoke_user_sessions',
        'update_settings',
        'update_document_numbering',
    ];

    /** @var array<string, int> */
    private const RANK = ['low' => 1, 'medium' => 2, 'high' => 3];

    public function __construct(private SettingService $settings) {}

    /**
     * Determines whether a tool invocation is prohibited on the WhatsApp channel.
     *
     * @param  string  $name  Tool identifier name
     * @param  ToolKind  $kind  Read or Write category
     * @param  string  $risk  Risk level ('low', 'medium', 'high')
     * @return bool True if action should be blocked on WhatsApp
     */
    public function blocks(string $name, ToolKind $kind, string $risk): bool
    {
        $disabled = $this->settings->get('ai_whatsapp_disabled_tools', self::DISABLED_BY_DEFAULT);
        if (! is_array($disabled)) {
            $disabled = self::DISABLED_BY_DEFAULT;
        }
        if (in_array($name, ['create_staff_user', 'create_customer'], true) || in_array($name, $disabled, true)) {
            return true;
        }
        if (! $this->settings->getBool('ai_whatsapp_allow_writes', false) && $kind === ToolKind::Write) {
            return true;
        }
        $noticeException = $name === 'approve_payment_notice'
            && $this->settings->getBool('ai_whatsapp_allow_notice_approval', false)
            && $this->settings->getBool('ai_whatsapp_pin_required', false);
        if (in_array($name, ['approve_payment_notice', 'reverse_payment_notice_approval', 'bulk_approve_matched_notices'], true) && ! $noticeException) {
            return true;
        }
        $max = (string) $this->settings->get('ai_whatsapp_max_risk', 'medium');
        $maxRank = self::RANK[$max] ?? self::RANK['medium'];
        $riskRank = self::RANK[$risk] ?? self::RANK['high'];

        return ! $noticeException && $riskRank > $maxRank;
    }
}
