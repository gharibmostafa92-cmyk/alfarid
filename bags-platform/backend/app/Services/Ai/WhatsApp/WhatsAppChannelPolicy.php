<?php

declare(strict_types=1);

namespace App\Services\Ai\WhatsApp;

use App\Services\Ai\ToolKind;
use App\Services\SettingService;

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
        $max = (string) $this->settings->get('ai_whatsapp_max_risk', 'medium');
        $maxRank = self::RANK[$max] ?? self::RANK['medium'];
        $riskRank = self::RANK[$risk] ?? self::RANK['high'];

        return $riskRank > $maxRank;
    }
}
