<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\Ai\Contracts\ToolProvider;

/**
 * Analyzes conversational user prompts and entity references to route intent to appropriate AI tool domains.
 */
final class DomainRouter
{
    /** @var array<string, list<string>> Domain keyword triggers supporting Arabic synonyms and dialect variants. */
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
            'عجز', 'زيادة', 'تحويل', 'تحويلات', 'حول', 'نقل بضاعة', 'نقل', 'تغطية', 'شحن', 'مشحون', 'استلام',
            'جرد', 'جرود', 'عد', 'فروقات', 'فرق الجرد', 'دفعة إنتاج', 'دفعات الإنتاج',
            'استلام إنتاج', 'تكلفة', 'التكلفة', 'راكد', 'ناقص', 'stock', 'transfer',
            'stocktake', 'stocktakes', 'batch', 'valuation', 'reconcile',
        ],
        'pricing' => [
            'قائمة أسعار', 'قايمة أسعار', 'قوائم الأسعار', 'شريحة', 'شرائح',
            'سعر الجملة', 'سعر الموزع', 'سعر العميل', 'ليه السعر', 'السعر طالع',
            'خصم', 'خصومات', 'كوبون', 'كوبونات', 'كود خصم', 'بروموكود',
            'عرض', 'عروض', 'تخفيض', 'تخفيضات', 'أوكازيون', 'شحن مجاني',
            'price list', 'coupon', 'promo', 'شيت أسعار', 'استيراد أسعار', 'صحة التسعير', 'هامش الربح',
        ],
        'finance' => [
            'خزنة', 'خزينة', 'خزائن', 'خزاين', 'الخزنة الرئيسية', 'درج', 'درج الكاشير',
            'حساب البنك', 'مصروف', 'مصروفات', 'مصاريف', 'نثرية', 'نثريات',
            'إيصال', 'فاتورة كهربا', 'تحويل فلوس', 'تحويل نقدية', 'تحويل خزينة',
            'اعتماد التحويل', 'اعتمد', 'اعتماد', 'سيولة', 'كاش', 'نقدية',
            'عجز الدرج', 'زيادة الدرج', 'رصيد المحفظة', 'محفظة العميل',
            'شحن المحفظة', 'استرداد', 'قفل اليوم', 'فحص الأرصدة', 'مطابقة',
            'وردية', 'ورديات', 'مالية', 'تقفيل', 'التحويلات المعلقة',
            'متأخرة', 'فئة', 'فئات', 'اتدفع', 'الدفع', 'مدفوع',
            'treasury', 'expense', 'cash', 'wallet balance',
        ],
        'shipping' => [
            'شركة شحن', 'شركات الشحن', 'منطقة شحن', 'مناطق الشحن', 'تعريفة الشحن', 'تعريفة التوصيل',
            'رسوم التوصيل', 'تكلفة التوصيل', 'رسوم التحصيل', 'مدينة التوصيل', 'مدن الشحن',
            'shipping company', 'shipping zone', 'shipping rate',
        ],
        'storefront' => [
            'بانر', 'سلايدر', 'الصفحة الرئيسية', 'السلة', 'كوبون السلة', 'إتمام الشراء', 'اتمام الشراء',
            'checkout', 'storefront',
        ],
    ];

    public function __construct(private ToolRegistry $registry) {}

    /**
     * Resolve eligible AI tool domains from text, mentions, selections, and conversation history.
     *
     * @param  string  $text  User prompt text.
     * @param  list<array<string, mixed>>  $mentions  Explicit @entity mentions.
     * @param  array<string, mixed>|null  $selection  Contextual client UI selection.
     * @param  AiConversation|null  $conversation  Active conversation session.
     * @param  list<string>  $attachments  File attachments accompanying the message.
     * @return list<string>|null List of domain names, or null if unrouted.
     */
    public function route(
        string $text,
        array $mentions = [],
        ?array $selection = null,
        ?AiConversation $conversation = null,
        array $attachments = [],
    ): ?array {
        $domains = [];

        // Image attachments imply catalog vision tools for analysis or product onboarding.
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
                'price_list', 'coupon', 'promotion' => 'pricing',
                'treasury', 'treasury_transfer', 'expense' => 'finance',
                'shipping_company', 'shipping_zone', 'shipping_rate' => 'shipping',
                'banner', 'cart' => 'storefront',
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
                if ($normWord === '') {
                    continue;
                }
                // Short keywords (<=2 chars) must match whole tokens to prevent substring false positives.
                if (mb_strlen($normWord) <= 2) {
                    $matched = (bool) preg_match('/(^|\s)'.preg_quote($normWord, '/').'(\s|$)/u', $norm);
                } else {
                    $matched = str_contains($norm, $normWord) || str_contains($lower, mb_strtolower($word));
                }
                if ($matched) {
                    $domains[$domain] = true;
                    break;
                }
            }
        }

        $domains = array_fill_keys($this->disambiguate($norm, array_keys($domains), $refs), true);

        // Retain previous tool domain context during multi-turn follow-up queries.
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

    /**
     * Disambiguate homonymous keywords across domain boundaries before selecting tool providers.
     *
     * Distinguishes wallet merchandise from customer stored balance, money transfers from goods transfers,
     * treasury balances from stock levels, and carrier shipping from free shipping promotional coupons.
     *
     * @param  string  $norm  Normalized prompt text.
     * @param  list<string>  $domains  Matched candidate domains.
     * @param  list<array<string, mixed>>  $refs  Referenced entity metadata.
     * @return list<string> Refined domain list.
     */
    private function disambiguate(string $norm, array $domains, array $refs): array
    {
        if ($domains === []) {
            return $domains;
        }
        $has = fn (string $word): bool => str_contains($norm, EntityResolver::normalizeForSearch($word));
        $hasAny = function (array $words) use ($has): bool {
            foreach ($words as $word) {
                if ($has($word)) {
                    return true;
                }
            }

            return false;
        };
        $mentionsCustomer = false;
        foreach ($refs as $ref) {
            if (in_array((string) ($ref['entity'] ?? ''), ['customer', 'customer_group'], true)) {
                $mentionsCustomer = true;
                break;
            }
        }
        $transferWord = $has('تحويل') || $has('حول');

        $drop = function (array $names) use (&$domains): void {
            $domains = array_values(array_diff($domains, $names));
        };
        $keep = function (string $name) use (&$domains): void {
            if (! in_array($name, $domains, true)) {
                $domains[] = $name;
            }
        };

        // Distinguish customer balance top-up from physical leather wallet products.
        if ($has('محفظة')) {
            $walletFinance = $hasAny(['عميل', 'رصيد', 'شحن', 'استرداد', 'جنيه', 'مبلغ']) || $mentionsCustomer;
            if ($walletFinance) {
                $keep('finance');
                $otherCatalog = false;
                foreach (self::KEYWORDS['catalog'] ?? [] as $word) {
                    if (in_array($word, ['محفظة', 'محفظه'], true)) {
                        continue;
                    }
                    $normWord = EntityResolver::normalizeForSearch($word);
                    if ($normWord !== '' && str_contains($norm, $normWord)) {
                        $otherCatalog = true;
                        break;
                    }
                }
                if (! $otherCatalog) {
                    $drop(['catalog']);
                }
                $otherInventory = false;
                foreach (self::KEYWORDS['inventory'] ?? [] as $word) {
                    if (in_array($word, ['شحن', 'مشحون'], true)) {
                        continue;
                    }
                    $normWord = EntityResolver::normalizeForSearch($word);
                    if ($normWord !== '' && str_contains($norm, $normWord)) {
                        $otherInventory = true;
                        break;
                    }
                }
                if (! $otherInventory) {
                    $drop(['inventory']);
                }
            } else {
                $drop(['finance']);
            }
        }

        // Distinguish cash movements between registers from physical product inter-branch transfers.
        if ($transferWord) {
            $moneyCtx = $hasAny(['خزنة', 'خزينة', 'درج', 'فلوس', 'نقدية', 'كاش', 'بنك', 'مبلغ', 'اعتماد', 'اعتمد', 'يعتمد', 'معلقة', 'معلق']);
            $goodsCtx = $hasAny(['بضاعة', 'قطعة', 'صنف', 'كرتونة', 'شنطة', 'منتج', 'مخزن']);
            if ($moneyCtx && ! $goodsCtx) {
                $keep('finance');
                $drop(['inventory', 'catalog']);
            } elseif ($goodsCtx && ! $moneyCtx) {
                $drop(['finance', 'catalog']);
            }
        }

        // Distinguish financial ledger balances from SKU stock quantities.
        if ($has('رصيد')) {
            $financeCtx = $hasAny(['خزنة', 'خزينة', 'درج', 'بنك', 'محفظة']);
            $inventoryCtx = $hasAny(['صنف', 'مخزن', 'قطعة']);
            if ($financeCtx && ! $inventoryCtx) {
                $keep('finance');
                $drop(['inventory']);
            } elseif ($inventoryCtx && ! $financeCtx) {
                $drop(['finance']);
            }
        }

        if ($hasAny(['وردية', 'ورديات']) && ! $hasAny(['مخزن', 'صنف', 'جرد', 'بضاعة'])) {
            $keep('finance');
            $drop(['inventory']);
        }

        if ($has('مصروف') && ! $hasAny(['مخزن', 'صنف', 'منتج', 'بضاعة', 'جرد'])) {
            $keep('finance');
            $drop(['inventory']);
        }

        if ($hasAny(['حركة', 'حركات']) && $hasAny(['خزنة', 'خزينة', 'درج', 'بنك', 'محفظة']) && ! $hasAny(['مخزن', 'صنف', 'بضاعة'])) {
            $drop(['inventory']);
        }

        if (($has('عجز') || $has('زيادة')) && ($has('درج') || $has('وردية'))) {
            $keep('finance');
            $drop(['inventory']);
        }

        // Distinguish carrier shipping rate settings from promotional free-shipping coupons.
        $shippingCtx = $hasAny(['توصيل', 'تعريفة', 'شركة شحن', 'شركات الشحن', 'منطقة شحن', 'رسوم التحصيل', 'مدن الشحن']);
        if ($has('شحن المحفظة') || ($has('محفظة') && $has('شحن'))) {
            $keep('finance');
            $drop(['shipping']);
        }
        if (($has('مشحون') || $has('اتشحن')) && $hasAny(['تحويل', 'مخزن']) && ! $shippingCtx) {
            $drop(['shipping']);
        }
        if ($has('شحن مجاني') && $hasAny(['كوبون', 'عرض']) && ! $hasAny(['تعريفة', 'منطقة شحن', 'شركة شحن'])) {
            $keep('pricing');
            $drop(['shipping', 'inventory']);
        }
        if ($shippingCtx) {
            $keep('shipping');
            if (! $hasAny(['جرد', 'رصيد صنف', 'تحويل'])) {
                $drop(['inventory']);
            }
        }

        return array_values($domains);
    }

    /**
     * Inspect previous model turns to extract the last executed tool name.
     */
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

    /**
     * Map a tool identifier to its owning business capability domain.
     */
    private function toolDomain(string $toolName): ?string
    {
        if (in_array($toolName, ['find_entities', 'ask_user_to_choose', 'bulk_actions'], true)) {
            return null;
        }
        $def = $this->registry->definitions()[$toolName] ?? null;
        if (! $def) {
            return null;
        }
        if (isset($def['provider']) && $def['provider'] instanceof ToolProvider) {
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
