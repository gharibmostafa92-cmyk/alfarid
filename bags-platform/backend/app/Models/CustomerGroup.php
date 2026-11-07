<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Customer tier classification group for wholesale price list assignments.
 */
class CustomerGroup extends Model
{
    protected $fillable = ['name', 'code', 'description', 'discount_percentage', 'is_active'];

    protected $casts = [
        'discount_percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function priceLists(): HasMany
    {
        return $this->hasMany(PriceList::class);
    }
}
