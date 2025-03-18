<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Coupon;
use App\Models\DeliveryArea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CartController extends Controller
{
    /**
     * Get cart details
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCart(Request $request)
    {
        $cart = $this->getOrCreateCart($request);

        // Load cart items and related product information
        $cart->load([
            'items.product.images',
            'items.variant',
            'coupon'
        ]);

        // Recalculate totals in case product prices have changed
        $cart = $this->recalculateCart($cart);

        return response()->json([
            'success' => true,
            'data' => [
                'cart' => $cart
            ]
        ]);
    }

    /**
     * Add product to cart
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addToCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'quantity' => 'required|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $cart = $this->getOrCreateCart($request);
        $productId = $request->product_id;
        $variantId = $request->variant_id;
        $quantity = $request->quantity;

        // Get product
        $product = Product::where('id', $productId)
            ->where('status', 'active')
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found or unavailable'
            ], 404);
        }

        // Check variant if provided
        $variant = null;
        if ($variantId) {
            $variant = ProductVariant::where('id', $variantId)
                ->where('product_id', $productId)
                ->where('is_active', true)
                ->first();

            if (!$variant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product variant not found or unavailable'
                ], 404);
            }
        }

        // Check if product is already in cart
        $cartItem = $cart->items()
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();

        if ($cartItem) {
            // Update quantity
            $cartItem->quantity += $quantity;
            $cartItem->save();

            // Recalculate subtotal
            $cartItem->calculateSubtotal()->save();
        } else {
            // Add new item to cart
            $price = $variant ? ($product->sale_price + $variant->additional_price) : $product->sale_price;

            $cartItem = new CartItem([
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'quantity' => $quantity,
                'unit_price' => $price,
                'subtotal' => $price * $quantity
            ]);

            $cart->items()->save($cartItem);
        }

        // Recalculate cart totals
        $cart = $this->recalculateCart($cart);

        // Load cart with updated items
        $cart->load([
            'items.product.images',
            'items.variant',
            'coupon'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product added to cart',
            'data' => [
                'cart' => $cart
            ]
        ]);
    }

    /**
     * Update cart item quantity
     *
     * @param Request $request
     * @param int $cartItemId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCartItem(Request $request, $cartItemId)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $cart = $this->getOrCreateCart($request);
        $cartItem = $cart->items()->find($cartItemId);

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found'
            ], 404);
        }

        // Update quantity
        $cartItem->quantity = $request->quantity;
        $cartItem->save();

        // Recalculate subtotal
        $cartItem->calculateSubtotal()->save();

        // Recalculate cart totals
        $cart = $this->recalculateCart($cart);

        // Load cart with updated items
        $cart->load([
            'items.product.images',
            'items.variant',
            'coupon'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cart updated',
            'data' => [
                'cart' => $cart
            ]
        ]);
    }

    /**
     * Remove item from cart
     *
     * @param Request $request
     * @param int $cartItemId
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeCartItem(Request $request, $cartItemId)
    {
        $cart = $this->getOrCreateCart($request);
        $cartItem = $cart->items()->find($cartItemId);

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found'
            ], 404);
        }

        // Delete item
        $cartItem->delete();

        // Recalculate cart totals
        $cart = $this->recalculateCart($cart);

        // Load cart with updated items
        $cart->load([
            'items.product.images',
            'items.variant',
            'coupon'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Item removed from cart',
            'data' => [
                'cart' => $cart
            ]
        ]);
    }

    /**
     * Apply coupon to cart
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function applyCoupon(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'coupon_code' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $cart = $this->getOrCreateCart($request);
        $couponCode = $request->coupon_code;

        // Find coupon
        $coupon = Coupon::where('code', $couponCode)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>=', now())
            ->first();

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired coupon code'
            ], 422);
        }

        // Check coupon usage limits
        if ($coupon->hasReachedUsageLimit()) {
            return response()->json([
                'success' => false,
                'message' => 'This coupon has reached its usage limit'
            ], 422);
        }

        // Check user-specific usage limit
        if ($request->user() && $coupon->hasUserReachedLimit($request->user()->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You have already used this coupon the maximum number of times'
            ], 422);
        }

        // Check minimum purchase amount
        if ($cart->subtotal < $coupon->minimum_purchase_amount) {
            return response()->json([
                'success' => false,
                'message' => "This coupon requires a minimum purchase of ৳{$coupon->minimum_purchase_amount}"
            ], 422);
        }

        // Apply coupon to cart
        $cart->coupon_id = $coupon->id;
        $cart->save();

        // Recalculate cart totals with coupon
        $cart = $this->recalculateCart($cart);

        // Load cart with updated items
        $cart->load([
            'items.product.images',
            'items.variant',
            'coupon'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Coupon applied successfully',
            'data' => [
                'cart' => $cart
            ]
        ]);
    }

    /**
     * Remove coupon from cart
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeCoupon(Request $request)
    {
        $cart = $this->getOrCreateCart($request);

        // Remove coupon
        $cart->coupon_id = null;
        $cart->save();

        // Recalculate cart totals
        $cart = $this->recalculateCart($cart);

        // Load cart with updated items
        $cart->load([
            'items.product.images',
            'items.variant'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Coupon removed successfully',
            'data' => [
                'cart' => $cart
            ]
        ]);
    }

    /**
     * Get shipping costs for different areas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShippingCosts(Request $request)
    {
        $cart = $this->getOrCreateCart($request);
        $orderAmount = $cart->subtotal - $cart->discount;

        $deliveryAreas = DeliveryArea::where('is_active', true)
            ->orderBy('name')
            ->get();

        $shippingOptions = [];

        foreach ($deliveryAreas as $area) {
            $shippingCost = $area->getDeliveryCharge($orderAmount);

            $shippingOptions[] = [
                'id' => $area->id,
                'name' => $area->name,
                'name_bn' => $area->name_bn,
                'city' => $area->city,
                'city_bn' => $area->city_bn,
                'shipping_cost' => $shippingCost,
                'min_order_amount' => $area->min_order_amount,
                'free_delivery_min_amount' => $area->free_delivery_min_amount,
                'estimated_delivery_time' => $area->estimated_delivery_time
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'shipping_options' => $shippingOptions
            ]
        ]);
    }

    /**
     * Update shipping cost in cart
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateShipping(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'delivery_area_id' => 'required|exists:delivery_areas,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $cart = $this->getOrCreateCart($request);
        $deliveryAreaId = $request->delivery_area_id;

        // Find delivery area
        $deliveryArea = DeliveryArea::find($deliveryAreaId);

        if (!$deliveryArea || !$deliveryArea->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid delivery area'
            ], 422);
        }

        // Check minimum order amount
        if ($cart->subtotal < $deliveryArea->min_order_amount) {
            return response()->json([
                'success' => false,
                'message' => "This area requires a minimum order of ৳{$deliveryArea->min_order_amount}"
            ], 422);
        }

        // Calculate shipping cost
        $orderAmount = $cart->subtotal - $cart->discount;
        $shippingCost = $deliveryArea->getDeliveryCharge($orderAmount);

        // Update cart
        $cart->shipping_charge = $shippingCost;
        $cart->save();

        // Recalculate cart totals
        $cart = $this->recalculateCart($cart);

        // Load cart with updated items
        $cart->load([
            'items.product.images',
            'items.variant',
            'coupon'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shipping cost updated',
            'data' => [
                'cart' => $cart,
                'delivery_area' => $deliveryArea
            ]
        ]);
    }

    /**
     * Clear cart
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearCart(Request $request)
    {
        $cart = $this->getOrCreateCart($request);

        // Delete all items
        $cart->items()->delete();

        // Reset cart
        $cart->coupon_id = null;
        $cart->subtotal = 0;
        $cart->discount = 0;
        $cart->shipping_charge = 0;
        $cart->vat = 0;
        $cart->total = 0;
        $cart->notes = null;
        $cart->save();

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared',
            'data' => [
                'cart' => $cart
            ]
        ]);
    }

    /**
     * Add notes to cart
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addNotes(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $cart = $this->getOrCreateCart($request);

        // Update notes
        $cart->notes = $request->notes;
        $cart->save();

        return response()->json([
            'success' => true,
            'message' => 'Notes added to cart',
            'data' => [
                'cart' => $cart
            ]
        ]);
    }

    /**
     * Get or create cart for current user/session
     *
     * @param Request $request
     * @return \App\Models\Cart
     */
    private function getOrCreateCart(Request $request)
    {
        if ($request->user()) {
            // User is logged in, get or create cart by user ID
            $cart = Cart::firstOrCreate(
                ['user_id' => $request->user()->id],
                [
                    'session_id' => null,
                    'subtotal' => 0,
                    'discount' => 0,
                    'shipping_charge' => 0,
                    'vat' => 0,
                    'total' => 0
                ]
            );

            // If there's a session cart, merge it with the user's cart
            if ($request->session()->has('cart_session_id')) {
                $sessionId = $request->session()->get('cart_session_id');
                $sessionCart = Cart::where('session_id', $sessionId)->first();

                if ($sessionCart && $sessionCart->id != $cart->id) {
                    // Move items from session cart to user cart
                    foreach ($sessionCart->items as $item) {
                        // Check if this product variant is already in user's cart
                        $existingItem = $cart->items()
                            ->where('product_id', $item->product_id)
                            ->where('product_variant_id', $item->product_variant_id)
                            ->first();

                        if ($existingItem) {
                            // Update quantity
                            $existingItem->quantity += $item->quantity;
                            $existingItem->save();
                            $existingItem->calculateSubtotal()->save();
                        } else {
                            // Clone item to user's cart
                            $newItem = $item->replicate();
                            $newItem->cart_id = $cart->id;
                            $newItem->save();
                        }
                    }

                    // Delete session cart
                    $sessionCart->items()->delete();
                    $sessionCart->delete();

                    // Remove session ID
                    $request->session()->forget('cart_session_id');
                }
            }
        } else {
            // Guest user, get or create cart by session ID
            $sessionId = $request->session()->get('cart_session_id');

            if (!$sessionId) {
                $sessionId = Str::uuid()->toString();
                $request->session()->put('cart_session_id', $sessionId);
            }

            $cart = Cart::firstOrCreate(
                ['session_id' => $sessionId],
                [
                    'user_id' => null,
                    'subtotal' => 0,
                    'discount' => 0,
                    'shipping_charge' => 0,
                    'vat' => 0,
                    'total' => 0
                ]
            );
        }

        return $cart;
    }

    /**
     * Recalculate cart totals
     *
     * @param \App\Models\Cart $cart
     * @return \App\Models\Cart
     */
    private function recalculateCart(Cart $cart)
    {
        // Reload items to ensure fresh data
        $cart->load('items.product', 'items.variant', 'coupon');

        // Recalculate each item's subtotal
        foreach ($cart->items as $item) {
            // Get current product price
            $product = $item->product;
            $variant = $item->variant;

            if (!$product || $product->status !== 'active') {
                // Product is no longer available, remove from cart
                $item->delete();
                continue;
            }

            if ($variant && !$variant->is_active) {
                // Variant is no longer available, remove from cart
                $item->delete();
                continue;
            }

            // Calculate current price
            $price = $variant ? ($product->sale_price + $variant->additional_price) : $product->sale_price;

            // Update item price and subtotal if changed
            if ($item->unit_price != $price) {
                $item->unit_price = $price;
                $item->subtotal = $price * $item->quantity;
                $item->save();
            }
        }

        // Reload to get fresh items after possible deletions
        $cart->load('items');

        // Calculate cart subtotal
        $subtotal = $cart->items->sum('subtotal');
        $cart->subtotal = $subtotal;

        // Apply coupon discount if any
        $cart->discount = 0;
        if ($cart->coupon_id && $cart->coupon) {
            $coupon = $cart->coupon;
            if ($coupon->isValid() && $subtotal >= $coupon->minimum_purchase_amount) {
                $cart->discount = $coupon->calculateDiscount($subtotal);
            } else {
                // Coupon no longer valid, remove it
                $cart->coupon_id = null;
            }
        }

        // Calculate VAT
        $vatableAmount = $subtotal - $cart->discount;
        $cart->vat = round($vatableAmount * 0.05, 2); // Assuming 5% VAT

        // Calculate total
        $cart->total = $subtotal - $cart->discount + $cart->shipping_charge + $cart->vat;

        // Save changes
        $cart->save();

        return $cart;
    }
}
