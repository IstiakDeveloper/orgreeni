<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\InventoryTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Get all orders with pagination
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $status = $request->input('status');
        $orderNumber = $request->input('order_number');
        $phone = $request->input('phone');
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');
        $paymentStatus = $request->input('payment_status');
        $paymentMethod = $request->input('payment_method');

        $ordersQuery = Order::with([
            'items.product',
            'deliveryArea',
            'deliverySlot',
            'user',
            'payments'
        ]);

        // Apply filters
        if ($status) {
            $ordersQuery->where('status', $status);
        }

        if ($orderNumber) {
            $ordersQuery->where('order_number', 'like', "%{$orderNumber}%");
        }

        if ($phone) {
            $ordersQuery->where('customer_phone', 'like', "%{$phone}%");
        }

        if ($fromDate) {
            $ordersQuery->whereDate('created_at', '>=', $fromDate);
        }

        if ($toDate) {
            $ordersQuery->whereDate('created_at', '<=', $toDate);
        }

        if ($paymentStatus) {
            $ordersQuery->where('payment_status', $paymentStatus);
        }

        if ($paymentMethod) {
            $ordersQuery->where('payment_method', $paymentMethod);
        }

        // Get paginated results ordered by latest first
        $orders = $ordersQuery->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'orders' => $orders
            ]
        ]);
    }

    /**
     * Get order details
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $order = Order::with([
            'items.product.images',
            'items.variant',
            'deliveryArea',
            'deliverySlot',
            'user',
            'payments',
            'statusHistories.user',
            'coupon'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $order
            ]
        ]);
    }

    /**
     * Update order status
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,confirmed,processing,picked,shipped,delivered,cancelled,returned,failed',
            'remarks' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::findOrFail($id);
        $oldStatus = $order->status;
        $newStatus = $request->status;

        // Start transaction
        DB::beginTransaction();

        try {
            // Update order status
            $order->status = $newStatus;

            // Update delivered_at if order is delivered
            if ($newStatus === 'delivered' && $oldStatus !== 'delivered') {
                $order->delivered_at = now();
            }

            // Update cancelled_at if order is cancelled
            if (in_array($newStatus, ['cancelled', 'failed']) && !in_array($oldStatus, ['cancelled', 'failed'])) {
                $order->cancelled_at = now();
                $order->cancellation_reason = $request->remarks;

                // Return inventory if cancelling the order
                $this->returnInventory($order);
            }

            // Save order changes
            $order->save();

            // Add status history entry
            $order->addStatusHistory($newStatus, $request->remarks, $request->user()->id);

            // Commit transaction
            DB::commit();

            // Reload order with related data
            $order->load([
                'items.product.images',
                'items.variant',
                'deliveryArea',
                'deliverySlot',
                'user',
                'payments',
                'statusHistories.user'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'data' => [
                    'order' => $order
                ]
            ]);

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update payment status
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePaymentStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'payment_status' => 'required|in:pending,paid,failed,refunded',
            'remarks' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::findOrFail($id);

        // Start transaction
        DB::beginTransaction();

        try {
            // Update order payment status
            $order->payment_status = $request->payment_status;
            $order->save();

            // Update related payment records if exists
            $payment = Payment::where('order_id', $order->id)->first();

            if ($payment) {
                switch ($request->payment_status) {
                    case 'paid':
                        $payment->status = 'completed';
                        $payment->paid_at = now();
                        break;
                    case 'failed':
                        $payment->status = 'failed';
                        break;
                    case 'refunded':
                        $payment->status = 'refunded';
                        break;
                    default:
                        $payment->status = 'pending';
                }

                $payment->save();
            }

            // Add status history entry
            $order->addStatusHistory(
                $order->status,
                "Payment status updated to {$request->payment_status}. " . ($request->remarks ?? ''),
                $request->user()->id
            );

            // Commit transaction
            DB::commit();

            // Reload order with related data
            $order->load([
                'payments',
                'statusHistories.user'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated successfully',
                'data' => [
                    'order' => $order
                ]
            ]);

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign delivery person to order
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignDeliveryPerson(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'delivery_person_id' => 'required|exists:users,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::findOrFail($id);
        $deliveryPersonId = $request->delivery_person_id;

        // Check if delivery person role is correct
        $deliveryPerson = User::find($deliveryPersonId);

        if (!$deliveryPerson || $deliveryPerson->role !== 'delivery') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid delivery person'
            ], 422);
        }

        // Update order
        $order->assigned_delivery_person_id = $deliveryPersonId;
        $order->save();

        // Add status history entry
        $order->addStatusHistory(
            $order->status,
            "Delivery person assigned: {$deliveryPerson->name}",
            $request->user()->id
        );

        // Reload order with related data
        $order->load([
            'deliveryPerson',
            'statusHistories.user'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Delivery person assigned successfully',
            'data' => [
                'order' => $order
            ]
        ]);
    }

    /**
     * Add note to order
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function addNote(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'note' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::findOrFail($id);

        // Update order notes
        $order->notes = $request->note;
        $order->save();

        // Add status history entry
        $order->addStatusHistory(
            $order->status,
            "Admin note added: {$request->note}",
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Note added successfully',
            'data' => [
                'order' => $order
            ]
        ]);
    }

    /**
     * Get order statistics
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats(Request $request)
    {
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        $query = Order::query();

        // Apply date filters if provided
        if ($fromDate) {
            $query->whereDate('created_at', '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        // Total orders count
        $totalOrders = $query->count();

        // Orders by status
        $statusCounts = $query->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        // Orders by payment method
        $paymentMethodCounts = $query->select('payment_method', DB::raw('count(*) as count'))
            ->groupBy('payment_method')
            ->get()
            ->pluck('count', 'payment_method')
            ->toArray();

        // Orders by payment status
        $paymentStatusCounts = $query->select('payment_status', DB::raw('count(*) as count'))
            ->groupBy('payment_status')
            ->get()
            ->pluck('count', 'payment_status')
            ->toArray();

        // Total revenue (excluding cancelled orders)
        $totalRevenue = $query->where('status', '!=', 'cancelled')->sum('total');

        // Average order value
        $averageOrderValue = $query->where('status', '!=', 'cancelled')->avg('total');

        return response()->json([
            'success' => true,
            'data' => [
                'total_orders' => $totalOrders,
                'status_counts' => $statusCounts,
                'payment_method_counts' => $paymentMethodCounts,
                'payment_status_counts' => $paymentStatusCounts,
                'total_revenue' => $totalRevenue,
                'average_order_value' => $averageOrderValue
            ]
        ]);
    }

    /**
     * Return inventory for cancelled orders
     *
     * @param Order $order
     * @return void
     */
    private function returnInventory(Order $order)
    {
        foreach ($order->items as $item) {
            $product = Product::find($item->product_id);
            $variant = $item->product_variant_id ? \App\Models\ProductVariant::find($item->product_variant_id) : null;

            if ($product) {
                if ($variant) {
                    // Get product stock
                    $stock = $variant->stocks()->first();

                    if (!$stock) {
                        // Create a new stock record if none exists
                        $stock = $variant->stocks()->create([
                            'product_id' => $product->id,
                            'quantity' => 0
                        ]);
                    }

                    // Calculate new quantity
                    $beforeQuantity = $stock->quantity;
                    $afterQuantity = $beforeQuantity + $item->quantity;

                    // Update stock
                    $stock->quantity = $afterQuantity;
                    $stock->save();

                    // Record inventory transaction
                    InventoryTransaction::create([
                        'type' => 'return',
                        'product_id' => $product->id,
                        'product_variant_id' => $variant->id,
                        'quantity' => $item->quantity,
                        'before_quantity' => $beforeQuantity,
                        'after_quantity' => $afterQuantity,
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'remarks' => "Order #{$order->order_number} cancelled - Inventory returned"
                    ]);
                } else {
                    // Get product stock
                    $stock = $product->stocks()->first();

                    if (!$stock) {
                        // Create a new stock record if none exists
                        $stock = $product->stocks()->create([
                            'quantity' => 0
                        ]);
                    }

                    // Calculate new quantity
                    $beforeQuantity = $stock->quantity;
                    $afterQuantity = $beforeQuantity + $item->quantity;

                    // Update stock
                    $stock->quantity = $afterQuantity;
                    $stock->save();

                    // Record inventory transaction
                    InventoryTransaction::create([
                        'type' => 'return',
                        'product_id' => $product->id,
                        'quantity' => $item->quantity,
                        'before_quantity' => $beforeQuantity,
                        'after_quantity' => $afterQuantity,
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'remarks' => "Order #{$order->order_number} cancelled - Inventory returned"
                    ]);
                }
            }
        }
    }
}
