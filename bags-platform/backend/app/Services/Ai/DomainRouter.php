<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;

final class DomainRouter
{
    /** @var array<string, list<string>> */
    private const KEYWORDS = [
        'catalog' => [
            'شنط', 'شنطة', 'شنطه', 'باك باك', 'كروس', 'هاند باج', 'ترولي', 'محفظة', 'محفظه', 'لابتوب',
            'منتج', 'منتجات', 'متغير', 'متغيرات', 'قسم', 'أقسام', 'اقسام', 'كتالوج',
            'سعر', 'أسعار', 'اسعار', 'قطاعي', 'باركود', 'كود', 'sku', 'barcode', 'slug',
            'صور', 'صورة', 'صوره', 'غلاف', 'وصف', 'خامة', 'خامه', 'مقاس', 'مقاسات', 'لون', 'ألوان', 'الوان',
            'نشر', 'انشر', 'أرشف', 'ارشف', 'مسودة', 'مسوده', 'مسودات', 'جاهزية', 'صحة الكتالوج',
            'p00', 'product', 'category', 'variant',
        ],
        'customers' => [
            'عميل', 'عملاء', 'تاجر', 'تجار', 'موزع', 'موزعين', 'طابور التجار', 'اعتماد تاجر',
            'سجل تجاري', 'بطاقة ضريبية', 'ائتمان', 'حد ائتمان', 'مجموعة عملاء', 'مجموعات العملاء',
            'dealer', 'customer',
        ],
        'settings' => [
            'إعدادات', 'اعدادات', 'ضريبة', 'ضرائب', 'ترقيم', 'بادئة', 'عداد', 'نافذة الإرجاع',
            'هوية الشركة', 'شعار الشركة', 'settings', 'numbering',
        ],
        'admin' => [
            'موظف', 'موظفين', 'فرع', 'فروع', 'مخزن', 'مخازن', 'دور', 'أدوار', 'ادوار',
            'صلاحية', 'صلاحيات', 'موقع تخزين', 'مواقع تخزين', 'رف', 'جلسات', 'تدقيق',
            'branch', 'role', 'staff', 'permission', 'location',
        ],
        'inventory' => [
            'مخزون', 'رصيد', 'متاح', 'محجوز', 'تالف', 'حركة', 'حركات', 'تسوية', 'تسويات',
            'عجز', 'زيادة', 'تحويل', 'تحويلات', 'نقل بضاعة', 'نقلت', 'تغطية', 'شحن', 'مشحون', 'استلام',
            'جرد', 'جرود', 'عد', 'فروقات', 'فرق الجرد', 'دفعة إنتاج', 'دفعات الإنتاج',
            'استلام إنتاج', 'تكلفة', 'التكلفة', 'راكد', 'ناقص', 'stock', 'transfer',
            'stocktake', 'stocktakes', 'batch', 'valuation', 'reconcile',
        ],
        'pricing' => [
            'قائمة أسعار', 'قايمة أسعار', 'قوائم الأسعار', 'شريحة', 'شرائح',
            'سعر الجملة', 'سعر الموزع', 'سعر العميل', 'ليه السعر', 'السعر طالع',
            'خصم', 'خصومات', 'كوبون', 'كوبونات', 'كود خصم', 'بروموكود',
            'عرض', 'عروض', 'تخفيض', 'تخفيضات', 'أوكازيون', 'شحن مجاني',
            'price list', 'coupon', 'promo',
        ],
    ];

    public function __construct(private ToolRegistry $registry) {}

    /**
     * @param  list<array<string,mixed>>  $mentions
     * @param  array<string,mixed>|null  $selection
     * @param  list<string>  $attachments
     * @return list<string>|null
     */
    public function route(
        string $text,
        array $mentions = [],
        ?array $selection = null,
        ?AiConversation $conversation = null,
        array $attachments = [],
    ): ?array {
        $domains = [];

        if ($attachments !== []) {
            $domains['catalog'] = true;
        }

        $refs = $mentions;
        if ($selection !== null) {
            $refs[] = $selection;
        }
        foreach ($refs as $ref) {
            $entity = (string) ($ref['entity'] ?? '');
            $mapped = match ($entity) {
                'category', 'product', 'variant' => 'catalog',
                'customer', 'customer_group' => 'customers',
                'branch', 'user', 'role', 'permission', 'location' => 'admin',
                'transfer', 'stocktake', 'production_receipt' => 'inventory',
                default => null,
            };
            if ($mapped !== null) {
                $domains[$mapped] = true;
            }
        }

        $norm = EntityResolver::normalizeForSearch(EntityResolver::normalizeDigits($text));
        $lower = mb_strtolower($text);

        foreach (self::KEYWORDS as $domain => $words) {
            foreach ($words as $word) {
                $normWord = EntityResolver::normalizeForSearch($word);
                if (($normWord !== '' && str_contains($norm, $normWord)) || str_contains($lower, mb_strtolower($word))) {
                    $domains[$domain] = true;
                    break;
                }
            }
        }

        if ($conversation !== null) {
            $lastToolName = $this->lastToolInConversation($conversation);
            if ($lastToolName !== null) {
                $lastDomain = $this->toolDomain($lastToolName);
                if ($lastDomain !== null) {
                    $domains[$lastDomain] = true;
                }
            }
        }

        if ($domains === []) {
            return null;
        }

        return array_keys($domains);
    }

    private function lastToolInConversation(AiConversation $conversation): ?string
    {
        $lastModelWithCall = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'model')
            ->whereNotNull('provider_parts')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['provider_parts']);

        foreach ($lastModelWithCall as $msg) {
            foreach ((array) $msg->provider_parts as $part) {
                if (is_array($part) && isset($part['functionCall']['name']) && is_string($part['functionCall']['name'])) {
                    return $part['functionCall']['name'];
                }
            }
        }

        return null;
    }

    private function toolDomain(string $toolName): ?string
    {
        if (in_array($toolName, ['find_entities', 'ask_user_to_choose', 'bulk_actions'], true)) {
            return null;
        }
        $def = $this->registry->definitions()[$toolName] ?? null;
        if (! $def) {
            return null;
        }
        if (isset($def['provider']) && $def['provider'] instanceof \App\Services\Ai\Contracts\ToolProvider) {
            return $def['provider']->domain();
        }
        if ($this->registry->isCustomerTool($toolName)) {
            return 'customers';
        }
        if (in_array($toolName, ['view_settings', 'view_document_numbering', 'update_settings', 'update_document_numbering'], true)) {
            return 'settings';
        }

        return 'admin';
    }
}
