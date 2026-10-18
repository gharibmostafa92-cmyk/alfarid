<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مورد. current_balance = ما ندين به له الآن: يبدأ من opening_balance،
 * يزيد باعتماد فواتيره (posted) وينقص بدفعاتنا — عبر SupplierFinanceService فقط.
 */
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'company_name',
        'email',
        'phone',
        'tax_number',
        'commercial_register',
        'address',
        'payment_terms_days',
        'opening_balance',
        'current_balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<SupplierUser, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(SupplierUser::class);
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** @return HasMany<SupplierInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    /** @return HasMany<SupplierPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** @return HasMany<SupplierDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(SupplierDelivery::class);
    }
}
