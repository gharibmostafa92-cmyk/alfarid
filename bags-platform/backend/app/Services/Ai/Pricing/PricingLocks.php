<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing;

use App\Models\AiPendingAction;
use App\Models\Coupon;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Pricing\PriceListService;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared row-lock + fingerprint comparison for pricing write tools.
 *
 * Lock order is always PriceList (by id) then PriceListItem (by id) —
 * the same order PriceListService uses. Fingerprints are compared only
 * while the action is still pending, mirroring ToolRegistry::checkVersion.
 */
final class PricingLocks
{
    /**
     * @param  list<int>  $itemIds
     * @param  array<string, string>  $expectedPlis  pli:{id} => fingerprint
     * @return array{list: PriceList, items: Collection<int, PriceListItem>}
     */
    public static function lockListAndItems(AiPendingAction $action, int $listId, array $itemIds, string $expectedPl, array $expectedPlis = []): array
    {
        $actor = $action->user_id !== null ? User::find($action->user_id) : null;

        $list = PriceList::query()->lockForUpdate()->find($listId);
        if (! $list) {
            throw new NotFoundHttpException('قائمة الأسعار غير موجودة.');
        }
        app(PriceListService::class)->assertListAccess($actor, $list);

        $items = PriceListItem::query()
            ->where('price_list_id', $list->id)
            ->when($itemIds !== [], fn ($q) => $q->whereIn('id', $itemIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($action->status === 'pending') {
            if (! hash_equals($expectedPl, PricingFingerprint::priceList($list))) {
                abort(409, 'حد عدّل قائمة الأسعار بعد الاقتراح؛ اطلب اقتراحًا جديدًا.');
            }
            foreach ($items as $item) {
                $key = 'pli:'.$item->id;
                if (isset($expectedPlis[$key]) && ! hash_equals($expectedPlis[$key], PricingFingerprint::item($item))) {
                    abort(409, 'حد عدّل بنود القائمة بعد الاقتراح؛ اطلب اقتراحًا جديدًا.');
                }
            }
        }

        return ['list' => $list, 'items' => $items];
    }

    public static function lockCoupon(AiPendingAction $action, int $couponId, string $expected): Coupon
    {
        $coupon = Coupon::query()->lockForUpdate()->find($couponId);
        if (! $coupon) {
            throw new NotFoundHttpException('الكوبون غير موجود.');
        }
        if ($action->status === 'pending' && ! hash_equals($expected, PricingFingerprint::coupon($coupon))) {
            abort(409, 'حد عدّل الكوبون بعد الاقتراح؛ اطلب اقتراحًا جديدًا.');
        }

        return $coupon;
    }

    public static function lockPromotion(AiPendingAction $action, int $promotionId, string $expected): Promotion
    {
        $promotion = Promotion::query()->lockForUpdate()->find($promotionId);
        if (! $promotion) {
            throw new NotFoundHttpException('العرض غير موجود.');
        }
        if ($action->status === 'pending' && ! hash_equals($expected, PricingFingerprint::promotion($promotion))) {
            abort(409, 'حد عدّل العرض بعد الاقتراح؛ اطلب اقتراحًا جديدًا.');
        }

        return $promotion;
    }
}
