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
                'price_list', 'coupon', 'promotion' => 'pricing',
                'treasury', 'treasury_transfer', 'expense' => 'finance',
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
                // Short keywords ('عد' count vs 'تعديل' modify, 'رف' shelf vs
                // 'ارفض' reject) must match whole words, or every sentence
                // containing them misroutes.
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
     * تمييز محفظة المنتج من محفظة العميل، وتحويل البضاعة من تحويل الفلوس،
     * ورصيد الصنف من رصيد الخزنة — بعد مطابقة الكلمات وقبل إرجاع الدومينات.
     *
     * @param  list<string>  $domains
     * @param  list<array<string,mixed>>  $refs
     * @return list<string>
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

        return array_values($domains);
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
