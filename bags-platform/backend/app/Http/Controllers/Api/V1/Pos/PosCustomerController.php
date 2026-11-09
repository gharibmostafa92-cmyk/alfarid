<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Shared\SearchTerm;
use App\Support\ArabicDigits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POS terminal customer search and quick customer creation.
 */
class PosCustomerController extends Controller
{
    /**
     * Search up to 10 active customers by phone or name, normalizing Eastern Arabic numerals.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $rawQuery = trim((string) ($data['q'] ?? ''));

        $query = Customer::query()
            ->whereIn('status', [CustomerStatus::Approved, CustomerStatus::Pending]);

        if ($rawQuery !== '') {
            $normalizedDigits = ArabicDigits::normalize($rawQuery);
            $normalizedPhone = Customer::normalizePhone($rawQuery);
            $phoneLike = SearchTerm::pattern($normalizedPhone !== '' ? $normalizedPhone : $normalizedDigits);
            $nameLike = SearchTerm::pattern(mb_strtolower($normalizedDigits));

            $query->where(function ($q) use ($phoneLike, $nameLike, $rawQuery): void {
                $q->where('phone', 'LIKE', $phoneLike)
                    ->orWhere('whatsapp_phone', 'LIKE', $phoneLike)
                    ->orWhereRaw('LOWER(name) LIKE ?', [$nameLike]);

                if ($rawQuery !== mb_strtolower($rawQuery)) {
                    $q->orWhere('name', 'LIKE', SearchTerm::pattern($rawQuery));
                }
            });
        }

        $customers = $query
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Customer $c): array => $this->formatCustomer($c))
            ->values()
            ->all();

        return response()->json(['data' => $customers]);
    }

    /**
     * Quick-create a retail or wholesale customer from the POS terminal during an active shift.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'type' => ['required', 'string', Rule::in(['retail', 'wholesale'])],
        ]);

        $normalizedPhone = Customer::normalizePhone($data['phone']);
        if (! preg_match('/^01[0125][0-9]{8}$/', $normalizedPhone)) {
            throw ValidationException::withMessages([
                'phone' => ['رقم الموبايل غير صالح؛ يجب أن يكون رقم موبايل مصري صحيح (01xxxxxxxxx).'],
            ]);
        }

        if (Customer::withTrashed()->where('phone', $normalizedPhone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['رقم الموبايل مسجل بالفعل.'],
            ]);
        }

        $type = CustomerType::from($data['type']);
        $status = $type === CustomerType::Retail
            ? CustomerStatus::Approved
            : CustomerStatus::Pending;

        try {
            $customer = Customer::create([
                'name' => trim($data['name']),
                'phone' => $normalizedPhone,
                'password' => Str::random(24),
                'type' => $type,
                'status' => $status,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw Customer::translateUniqueViolation($e);
        }

        return response()->json([
            'message' => 'تم إضافة العميل بنجاح.',
            'data' => $this->formatCustomer($customer),
        ], 201);
    }

    /**
     * @return array{id: int, name: string, phone: string, type: string, loyalty_points: int, points: int}
     */
    private function formatCustomer(Customer $customer): array
    {
        $type = $customer->type === CustomerType::Retail ? 'retail' : 'wholesale';
        $points = (int) ($customer->getAttribute('loyalty_points') ?? 0);

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'type' => $type,
            'loyalty_points' => $points,
            'points' => $points,
        ];
    }
}
