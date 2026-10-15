<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Exceptions\BusinessException;
use App\Models\InventoryIdempotencyKey;
use App\Services\BranchAccess;
use App\Services\IdempotencyFingerprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * إنشاء بمفتاح عدم تكرار لنهايات الإنشاء (تحويل/جرد): إعادة المحاولة بعد
 * timeout بنفس المفتاح تعيد النموذج المنشأ بدل نسخة مكررة، ونفس المفتاح
 * بحمولة مختلفة يُرفض 409. المفتاح يُدرج في نفس معاملة الإنشاء، ففشل
 * الإنشاء يلغيه معه (L-7 — نفس نمط مفتاح التسويات).
 */
trait HandlesIdempotentCreation
{
    /**
     * @param  list<string>  $relations
     * @param  callable(): Model  $creator
     */
    private function createIdempotently(string $context, array $payload, int $userId, ?string $idempotencyKey, string $modelClass, array $relations, callable $creator): Model
    {
        if ($idempotencyKey === null) {
            return $creator();
        }

        $requestHash = IdempotencyFingerprint::hash(['__context' => $context, 'payload' => $payload]);

        try {
            return DB::transaction(function () use ($userId, $idempotencyKey, $requestHash, $creator): Model {
                $record = InventoryIdempotencyKey::query()->create([
                    'user_id' => $userId,
                    'key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                ]);

                $model = $creator();

                $record->update(['response' => [$model->id], 'status_code' => 201]);

                return $model;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'inventory_idempotency_keys_user_id_key_unique')) {
                throw $e;
            }

            $record = InventoryIdempotencyKey::query()
                ->where('user_id', $userId)->where('key', $idempotencyKey)->first();

            if ($record === null || ! hash_equals($record->request_hash, $requestHash)) {
                throw new BusinessException('مفتاح عدم التكرار هذا مستخدم مسبقًا مع بيانات مختلفة.', [], 409);
            }

            $model = $modelClass::query()->with($relations)->findOrFail((int) ($record->response[0] ?? 0));
            app(BranchAccess::class)->assertBranch($userId, (int) ($model->getAttribute('branch_id') ?? $model->getAttribute('from_branch_id')));

            return $model;
        }
    }
}
