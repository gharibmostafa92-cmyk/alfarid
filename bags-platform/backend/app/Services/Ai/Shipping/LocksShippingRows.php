<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
use App\Models\ShippingZone;

trait LocksShippingRows
{
    public function lockTargets(AiPendingAction $action): void
    {
        foreach ($action->expected_versions ?? [] as $key => $version) {
            [$type, $id] = explode(':', (string) $key, 2);
            $model = match ($type) {
                'company' => ShippingCompany::class,
                'zone' => ShippingZone::class,
                'rate' => ShippingRate::class,
                default => null,
            };
            if ($model === null) {
                throw new BusinessException('تعذّر قفل سجل الشحن.', [], 409);
            }
            $record = $model::query()->lockForUpdate()->find($id);
            if (! $record) {
                throw new BusinessException('السجل اتحذف.', [], 409);
            }
            ShippingVersions::assertSame($record, (string) $version);
        }
    }
}
