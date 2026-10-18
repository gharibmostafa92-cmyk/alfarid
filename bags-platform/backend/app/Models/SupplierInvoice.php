<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupplierInvoiceStatus;
use Database\Factories\SupplierInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * فاتورة مورد: اعتمادها (posted) يثبت الاستحقاق في رصيد المورد،
 * وسدادها يتحرك عبر SupplierPayment — الآثار كلها في SupplierFinanceService.
 */
class SupplierInvoice extends Model
{
    /** @use HasFactory<SupplierInvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'supplier_invoice_no',
        'supplier_id',
        'purchase_order_id',
        'issue_date',
        'due_date',
        'subtotal',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'status',
        'attachment_path',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'status' => SupplierInvoiceStatus::class,
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return HasMany<SupplierPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'supplier_invoice_id');
    }
}
