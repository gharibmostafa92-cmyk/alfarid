<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Shopping cart model for guest users (via cart_token) or registered customers (via customer_id).
 * Guest carts are merged into customer carts upon authentication via CartService.
 */
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    protected $fillable = [
        'cart_token',
        'customer_id',
        'coupon_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Cart $cart): void {
            $cart->cart_token ??= (string) Str::uuid();
        });
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
