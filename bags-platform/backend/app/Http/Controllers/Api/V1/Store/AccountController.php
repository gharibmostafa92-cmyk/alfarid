<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** عناوين العميل المحفوظة — تُملأ تلقائيًا من طلباته لتعبئة الفورم لاحقًا. */
class AccountController extends Controller
{
    public function addresses(Request $request): JsonResponse
    {
        $addresses = $request->user()->addresses()->with('city:id,name_ar,name_en')
            ->orderByDesc('is_default')->latest('id')->get()
            ->map(fn ($address): array => [
                'id' => $address->id,
                'city_id' => $address->city_id,
                'city_name_ar' => $address->city?->name_ar,
                'city_name_en' => $address->city?->name_en,
                'address_line' => $address->address_line,
                'is_default' => (bool) $address->is_default,
            ])->all();

        return response()->json(['data' => $addresses]);
    }
}
