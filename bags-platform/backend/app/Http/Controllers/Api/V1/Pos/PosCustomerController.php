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

        if (mb_strlen($rawQuery) < 2) {
            return response()->json(['data' => []]);
        }

        $query = Customer::query()
            ->whereIn('status', [CustomerStatus::Approved, CustomerStatus::Pending]);

        $normalizedDigits = ArabicDigits::normalize($rawQuery);
        $phone = preg_match('/\A\+?[0-9][0-9 ()-]*\z/', $normalizedDigits) === 1
            ? Customer::normalizePhone($normalizedDigits) : '';
        $nameLike = SearchTerm::pattern(mb_strtolower($normalizedDigits));
        $query->where(function ($match) use ($phone, $nameLike): void {
            $match->whereRaw('LOWER(name) LIKE ?', [$nameLike])
                ->orWhereRaw('LOWER(company_name) LIKE ?', [$nameLike]);
            if ($phone !== '') {
                $match->orWhere('phone', 'LIKE', SearchTerm::pattern($phone))
                    ->orWhere('whatsapp_phone', 'LIKE', SearchTerm::pattern($phone));
            }
        });
        if ($phone !== '') {
            $query->orderByRaw('CASE WHEN phone = ? THEN 0 WHEN whatsapp_phone = ? THEN 1 ELSE 2 END', [$phone, $phone]);
        }

        $customers = $query
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'name', 'phone', 'type', 'status'])
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
        if (! preg_match('/\A01[0125][0-9]{8}\z/', $normalizedPhone)) {
            throw ValidationException::withMessages([
                'phone' => ['رقم الموبايل غير صالح؛ يجب أن يكون رقم موبايل مصري صحيح (01xxxxxxxxx).'],
            ]);
        }

        $existing = Customer::withTrashed()->where('phone', $normalizedPhone)->first();
        if ($existing !== null) {
            if ($existing->trashed()) {
                throw ValidationException::withMessages([
                    'phone' => ['رقم الموبايل مسجل لحساب عميل محذوف ناعمًا؛ يرجى استعادته من لوحة الإدارة.'],
                ]);
            }
            if ($existing->status === CustomerStatus::Suspended) {
                throw ValidationException::withMessages([
                    'phone' => ['رقم الموبايل مسجل لحساب عميل موقوف؛ لا يمكن استخدامه حاليًا.'],
                ]);
            }
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
                'password' => null,
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
     * @return array{id: int, name: string, phone: string, type: string, status: string, loyalty_points: int, points: int}
     */
    private function formatCustomer(Customer $customer): array
    {
        $type = $customer->type === CustomerType::Retail ? 'retail' : 'wholesale';
        $status = $customer->status instanceof CustomerStatus ? $customer->status->value : (string) $customer->status;
        $points = (int) ($customer->getAttribute('loyalty_points') ?? 0);

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'type' => $type,
            'status' => $status,
            'loyalty_points' => $points,
            'points' => $points,
        ];
    }
}
