<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Orders\CartService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CartVariantImageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cart_line_prefers_the_chosen_variant_image(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);
        $product->addMedia(UploadedFile::fake()->image('product-only.jpg'))->toMediaCollection('images');
        $variant->addMedia(UploadedFile::fake()->image('variant-only.jpg'))->toMediaCollection('images');
        $sibling = $product->variants()->whereKeyNot($variant->id)->firstOrFail();
        $sibling->addMedia(UploadedFile::fake()->image('sibling-only.jpg'))->toMediaCollection('images');

        $cart = Cart::factory()->create();
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'qty' => 1,
        ]);

        $cart = $cart->fresh();
        $presented = app(CartService::class)->present($cart, null);
        $image = (string) ($presented['items'][0]['image'] ?? '');

        $this->assertStringContainsString('variant-only', $image);
        $this->assertStringNotContainsString('product-only', $image);
        $this->assertStringNotContainsString('sibling-only', $image);

        $line = $cart->items->first();
        $this->assertFalse($line->variant->product->relationLoaded('variants'));
        $this->assertTrue($line->variant->relationLoaded('media'));
    }
}
