<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Cart;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\DeliverySlot;
use App\Models\DeliveryArea;
use App\Models\Product;
use App\Models\InventoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OrderController extends Controller
{
    /**
     * Place a new order
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function placeOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|size:11',
            'customer_email' => 'nullable|email|max:255',
            'shipping_address' => 'required|string',
            'delivery_area_id' => 'required|exists:delivery_areas,id',
            'delivery_slot_id' => 'required|exists:delivery_slots,id',
            'delivery_date' => 'required|date|after_or_equal:today',
            'payment_method' => 'required|in:cash_on_delivery,bkash,nagad,rocket,card,bank_transfer',
            'transaction_id' => 'required_unless:payment_method,cash_on_delivery',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Get current cart
        $cart = $this->getCart($request);

        if (!$cart || $cart->items->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Your cart is empty'
            ], 422);
        }

        // Validate delivery area
        $deliveryArea = DeliveryArea::find($request->delivery_area_id);
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

        // Validate delivery slot
        $deliverySlot = DeliverySlot::find($request->delivery_slot_id);
        if (!$deliverySlot || !$deliverySlot->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid delivery slot'
            ], 422);
        }

        // Check if delivery slot is available for the selected date
        $deliveryDate = Carbon::parse($request->delivery_date);
        if (!$deliverySlot->isAvailable($deliveryDate)) {
            return response()->json([
                'success' => false,
                'message' => 'This delivery slot is no longer available for the selected date'
            ], 422);
        }

        // Recalculate shipping charge based on delivery area
        $orderAmount = $cart->subtotal - $cart->discount;
        $shippingCharge = $deliveryArea->getDeliveryCharge($orderAmount);

        // Start database transaction
        DB::beginTransaction();

        try {
            // Create order
            $order = new Order();
            $order->order_number = $order->generateOrderNumber();
            $order->user_id = $request->user() ? $request->user()->id : null;
            $order->customer_name = $request->customer_name;
            $order->customer_phone = $request->customer_phone;
            $order->customer_email = $request->customer_email;
            $order->shipping_address = $request->shipping_address;
            $order->delivery_area_id = $request->delivery_area_id;
            $order->delivery_slot_id = $request->delivery_slot_id;
            $order->delivery_date = $request->delivery_date;
            $order->coupon_id = $cart->coupon_id;
            $order->subtotal = $cart->subtotal;
            $order->discount = $cart->discount;
            $order->shipping_charge = $shippingCharge;
            $order->vat = $cart->vat;
            $order->total = ($cart->subtotal - $cart->discount) + $shippingCharge + $cart->vat;
            $order->status = 'pending';
            $order->payment_status = 'pending';
            $order->payment_method = $request->payment_method;
            $order->transaction_id = $request->transaction_id;
            $order->notes = $request->notes;
            $order->save();

            // Create order items
            foreach ($cart->items as $cartItem) {
                $product = $cartItem->product;
                $variant = $cartItem->variant;

                // Skip if product no longer exists or is inactive
                if (!$product || $product->status !== 'active') {
                    continue;
                }

                // Skip if variant no longer exists or is inactive
                if ($cartItem->product_variant_id && (!$variant || !$variant->is_active)) {
                    continue;
                }

                // Create order item
                $orderItem = new OrderItem();
                $orderItem->order_id = $order->id;
                $orderItem->product_id = $cartItem->product_id;
                $orderItem->product_variant_id = $cartItem->product_variant_id;
                $orderItem->product_name = $product->name;
                $orderItem->variant_name = $variant ? $variant->name : null;
                $orderItem->quantity = $cartItem->quantity;
                $orderItem->unit_price = $cartItem->unit_price;
                $orderItem->subtotal = $cartItem->subtotal;
                $order->items()->save($orderItem);

                // Update inventory
                $this->updateInventory($product, $variant, $cartItem->quantity, $order);
            }

            // Create payment record for non-COD orders
            if ($request->payment_method !== 'cash_on_delivery') {
                $payment = new Payment();
                $payment->order_id = $order->id;
                $payment->amount = $order->total;
                $payment->payment_method = $request->payment_method;
                $payment->status = 'pending'; // Will be verified by admin
                $payment->transaction_id = $request->transaction_id;
                $payment->save();
            }

            // Add initial status history
            $order->addStatusHistory('pending', 'Order placed');

            // Clear cart
            $cart->items()->delete();
            $cart->delete();

            // Clear session if guest
            if (!$request->user() && $request->session()->has('cart_session_id')) {
                $request->session()->forget('cart_session_id');
            }

            // Commit transaction
            DB::commit();

            // Load order details
            $order->load('items', 'deliveryArea', 'deliverySlot');

            return response()->json([
                'success' => true,
                'message' => 'Order placed successfully',
                'data' => [
                    'order' => $order
                ]
            ], 201);

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to place order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get order details
     *
     * @param Request $request
     * @param string $orderNumber
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrder(Request $request, $orderNumber)
    {
        $user = $request->user();

        $orderQuery = Order::where('order_number', $orderNumber)
            ->with(['items', 'deliveryArea', 'deliverySlot', 'statusHistories', 'payments']);

        // If user is logged in, ensure they can only view their own orders
        if ($user) {
            $order = $orderQuery->where('user_id', $user->id)->first();
        } else {
            // For guest users, verify with phone number
            $validator = Validator::make($request->all(), [
                'phone' => 'required|string|size:11',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide your phone number to view this order',
                    'errors' => $validator->errors()
                ], 422);
            }

            $order = $orderQuery->where('customer_phone', $request->phone)->first();
        }

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $order
            ]
        ]);
    }

    /**
     * Cancel an order
     *
     * @param Request $request
     * @param string $orderNumber
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelOrder(Request $request, $orderNumber)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        $orderQuery = Order::where('order_number', $orderNumber);

        // If user is logged in, ensure they can only cancel their own orders
        if ($user) {
            $order = $orderQuery->where('user_id', $user->id)->first();
        } else {
            // For guest users, verify with phone number
            $phoneValidator = Validator::make($request->all(), [
                'phone' => 'required|string|size:11',
            ]);

            if ($phoneValidator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide your phone number to cancel this order',
                    'errors' => $phoneValidator->errors()
                ], 422);
            }

            $order = $orderQuery->where('customer_phone', $request->phone)->first();
        }

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        // Check if order can be cancelled
        if (!$order->canBeCancelled()) {
            return response()->json([
                'success' => false,
                'message' => 'This order cannot be cancelled'
            ], 422);
        }

        // Start database transaction
        DB::beginTransaction();

        try {
            // Update order status
            $order->status = 'cancelled';
            $order->cancelled_at = now();
            $order->cancellation_reason = $request->reason;
            $order->save();

            // Add status history
            $order->addStatusHistory('cancelled', $request->reason);

            // Return inventory
            foreach ($order->items as $item) {
                $product = Product::find($item->product_id);
                $variant = $item->product_variant_id ? \App\Models\ProductVariant::find($item->product_variant_id) : null;

                if ($product) {
                    // Add inventory back
                    $this->updateInventory($product, $variant, -$item->quantity, $order, 'return');
                }
            }

            // Commit transaction
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order cancelled successfully',
                'data' => [
                    'order' => $order->fresh(['items', 'statusHistories'])
                ]
            ]);

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify payment for an order
     *
     * @param Request $request
     * @param string $orderNumber
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPayment(Request $request, $orderNumber)
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string|max:100',
            'payment_method' => 'required|in:bkash,nagad,rocket,card,bank_transfer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        $orderQuery = Order::where('order_number', $orderNumber);

        // If user is logged in, ensure they can only update their own orders
        if ($user) {
            $order = $orderQuery->where('user_id', $user->id)->first();
        } else {
            // For guest users, verify with phone number
            $phoneValidator = Validator::make($request->all(), [
                'phone' => 'required|string|size:11',
            ]);

            if ($phoneValidator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide your phone number to update this order',
                    'errors' => $phoneValidator->errors()
                ], 422);
            }

            $order = $orderQuery->where('customer_phone', $request->phone)->first();
        }

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        // Check if payment can be updated
        if ($order->payment_status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Payment has already been confirmed for this order'
            ], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update payment for a cancelled order'
            ], 422);
        }

        // Update payment information
        $order->payment_method = $request->payment_method;
        $order->transaction_id = $request->transaction_id;
        $order->save();

        // Create or update payment record
        $payment = Payment::firstOrNew([
            'order_id' => $order->id,
            'payment_method' => $request->payment_method
        ]);

        $payment->amount = $order->total;
        $payment->transaction_id = $request->transaction_id;
        $payment->status = 'pending'; // Will be verified by admin
        $payment->save();

        // Add note to status history
        $order->addStatusHistory(
            $order->status,
            "Payment information updated. Method: {$request->payment_method}, Transaction ID: {$request->transaction_id}"
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment information updated. Your payment will be verified soon.',
            'data' => [
                'order' => $order->fresh(['payments']),
                'payment' => $payment
            ]
        ]);
    }

    /**
     * Track order status
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function trackOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_number' => 'required|string',
            'phone' => 'required|string|size:11',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::where('order_number', $request->order_number)
            ->where('customer_phone', $request->phone)
            ->with(['items', 'deliveryArea', 'deliverySlot', 'statusHistories'])
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found with the provided details'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $order
            ]
        ]);
    }

    /**
     * Get delivery slots for a specific date
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDeliverySlots(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date|after_or_equal:today',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $date = Carbon::parse($request->date);

        $slots = DeliverySlot::where('is_active', true)
            ->orderBy('start_time')
            ->get();

        $availableSlots = [];

        foreach ($slots as $slot) {
            $remainingSlots = $slot->getRemainingSlots($date);

            $availableSlots[] = [
                'id' => $slot->id,
                'name' => $slot->name,
                'name_bn' => $slot->name_bn,
                'start_time' => $slot->start_time->format('H:i'),
                'end_time' => $slot->end_time->format('H:i'),
                'available' => $remainingSlots > 0,
                'remaining_slots' => $remainingSlots,
                'max_orders' => $slot->max_orders
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'delivery_date' => $date->format('Y-m-d'),
                'delivery_slots' => $availableSlots
            ]
        ]);
    }

    /**
     * Get current cart
     *
     * @param Request $request
     * @return \App\Models\Cart|null
     */
    private function getCart(Request $request)
    {
        if ($request->user()) {
            // User is logged in, get cart by user ID
            return Cart::where('user_id', $request->user()->id)->first();
        } elseif ($request->session()->has('cart_session_id')) {
            // Guest user, get cart by session ID
            $sessionId = $request->session()->get('cart_session_id');
            return Cart::where('session_id', $sessionId)->first();
        }

        return null;
    }

    /**
     * Update inventory for product and variant
     *
     * @param \App\Models\Product $product
     * @param \App\Models\ProductVariant|null $variant
     * @param int $quantity
     * @param \App\Models\Order $order
     * @param string $type
     * @return void
     */
    private function updateInventory($product, $variant = null, $quantity, $order, $type = 'sale')
    {
        // Get product stock
        if ($variant) {
            $stock = $variant->stocks()->orderBy('expiry_date')->first();

            if (!$stock) {
                // Create a new stock record if none exists
                $stock = $variant->stocks()->create([
                    'product_id' => $product->id,
                    'quantity' => 0
                ]);
            }

            // Calculate new quantity
            $beforeQuantity = $stock->quantity;
            $afterQuantity = $beforeQuantity - $quantity;

            // Update stock
            $stock->quantity = $afterQuantity;
            $stock->save();

            // Record inventory transaction
            InventoryTransaction::create([
                'type' => $type,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $afterQuantity,
                'reference_type' => 'order',
                'reference_id' => $order->id,
                'remarks' => "Order #{$order->order_number}"
            ]);
        } else {
            $stock = $product->stocks()->orderBy('expiry_date')->first();

            if (!$stock) {
                // Create a new stock record if none exists
                $stock = $product->stocks()->create([
                    'quantity' => 0
                ]);
            }

            // Calculate new quantity
            $beforeQuantity = $stock->quantity;
            $afterQuantity = $beforeQuantity - $quantity;

            // Update stock
            $stock->quantity = $afterQuantity;
            $stock->save();

            // Record inventory transaction
            InventoryTransaction::create([
                'type' => $type,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $afterQuantity,
                'reference_type' => 'order',
                'reference_id' => $order->id,
                'remarks' => "Order #{$order->order_number}"
            ]);
        }
    }
}
