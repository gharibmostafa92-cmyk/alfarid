<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PurchaseRequestStatus;
use App\Models\Branch;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequest>
 */
class PurchaseRequestFactory extends Factory
{
    protected $model = PurchaseRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_number' => 'PR-'.str_pad((string) fake()->unique()->numberBetween(1001, 999999), 6, '0', STR_PAD_LEFT),
            'branch_id' => Branch::factory(),
            'requested_by' => User::factory(),
            'status' => PurchaseRequestStatus::Draft,
            'notes' => null,
        ];
    }
}
