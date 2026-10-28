<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\TreasuryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates wholesale payment notice administrative approval and destination treasury selection.
 */
class ReviewPaymentNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Payment notice receipts must only be credited to bank accounts or main safes, never POS drawers.
            'treasury_id' => ['nullable', 'integer', Rule::exists('treasuries', 'id')
                ->where('is_active', true)
                ->whereIn('type', [TreasuryType::BankAccount->value, TreasuryType::MainSafe->value])],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
