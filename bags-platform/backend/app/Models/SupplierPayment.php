<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupplierPaymentMethod;
use Database\Factories\SupplierPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** دفعة مسددة لمورد: تخفض رصيده الدائن وتُحدّث فاتورتها المرتبطة إن وجدت. */
class SupplierPayment extends Model
{
    /** @use HasFactory<SupplierPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'payment_number',
        'supplier_id',
        'supplier_invoice_id',
        'amount',
        'payment_method',
        'reference_no',
        'payment_date',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_method' => SupplierPaymentMethod::class,
            'payment_date' => 'date',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<SupplierInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
