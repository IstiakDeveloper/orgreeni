<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductStock;
use App\Models\InventoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * Get inventory with pagination and filters
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');
        $categoryId = $request->input('category_id');
        $brandId = $request->input('brand_id');
        $stockStatus = $request->input('stock_status'); // 'in_stock', 'out_of_stock', 'low_stock'

        $productsQuery = Product::with(['category', 'brand', 'variants', 'stocks']);

        // Apply search filter
        if ($search) {
            $productsQuery->where(function($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('name_bn', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // Apply category filter
        if ($categoryId) {
            $productsQuery->where('category_id', $categoryId);
        }

        // Apply brand filter
        if ($brandId) {
            $productsQuery->where('brand_id', $brandId);
        }

        // Get products
        $products = $productsQuery->orderBy('name')->paginate($perPage);

        // Calculate stock and apply stock status filter if needed
        $filteredProducts = $products->getCollection()->map(function($product) {
            $product->current_stock = $product->getCurrentStock();
            $product->is_low_stock = $product->isLowStock();
            $product->is_in_stock = $product->isInStock();

            return $product;
        });

        // Apply stock status filter manually (after calculation)
        if ($stockStatus) {
            $filteredProducts = $filteredProducts->filter(function($product) use ($stockStatus) {
                if ($stockStatus === 'in_stock') {
                    return $product->is_in_stock;
                } else if ($stockStatus === 'out_of_stock') {
                    return !$product->is_in_stock;
                } else if ($stockStatus === 'low_stock') {
                    return $product->is_low_stock;
                }
                return true;
            });
        }

        // Replace collection in paginator
        $products->setCollection($filteredProducts);

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $products
            ]
        ]);
    }

    /**
     * Get low stock products
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLowStock(Request $request)
    {
        $perPage = $request->input('per_page', 15);

        // Get all products
        $products = Product::with(['category', 'brand', 'variants', 'stocks'])
            ->where('status', 'active')
            ->get();

        // Filter low stock products manually (need to calculate stock first)
        $lowStockProducts = $products->filter(function($product) {
            return $product->isLowStock();
        });

        // Sort by stock level (ascending)
        $lowStockProducts = $lowStockProducts->sortBy(function($product) {
            return $product->getCurrentStock();
        })->values();

        // Paginate manually
        $page = $request->input('page', 1);
        $offset = ($page - 1) * $perPage;
        $paginatedProducts = $lowStockProducts->slice($offset, $perPage);

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $paginatedProducts,
            $lowStockProducts->count(),
            $perPage,
            $page,
            ['path' => $request->url()]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $paginator
            ]
        ]);
    }

    /**
     * Get inventory transactions
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTransactions(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $productId = $request->input('product_id');
        $variantId = $request->input('variant_id');
        $type = $request->input('type');
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        $transactionsQuery = InventoryTransaction::with(['product', 'variant', 'createdBy']);

        // Apply product filter
        if ($productId) {
            $transactionsQuery->where('product_id', $productId);
        }

        // Apply variant filter
        if ($variantId) {
            $transactionsQuery->where('product_variant_id', $variantId);
        }

        // Apply type filter
        if ($type) {
            $transactionsQuery->where('type', $type);
        }

        // Apply date filters
        if ($fromDate) {
            $transactionsQuery->whereDate('created_at', '>=', $fromDate);
        }

        if ($toDate) {
            $transactionsQuery->whereDate('created_at', '<=', $toDate);
        }

        // Get transactions ordered by latest first
        $transactions = $transactionsQuery->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'transactions' => $transactions
            ]
        ]);
    }

    /**
     * Update product stock
     *
     * @param Request $request
     * @param int $productId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStock(Request $request, $productId)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:add,subtract,set',
            'quantity' => 'required|integer|min:1',
            'variant_id' => 'nullable|exists:product_variants,id',
            'batch_number' => 'nullable|string|max:50',
            'expiry_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $product = Product::findOrFail($productId);
        $variantId = $request->variant_id;
        $type = $request->type;
        $quantity = $request->quantity;
        $remarks = $request->remarks ?? ($type === 'add' ? 'Stock added manually' : ($type === 'subtract' ? 'Stock subtracted manually' : 'Stock set manually'));

        // Determine if we're working with a variant or the main product
        if ($variantId) {
            $variant = ProductVariant::where('id', $variantId)
                ->where('product_id', $productId)
                ->firstOrFail();

            // Get or create stock record
            $stock = ProductStock::firstOrNew([
                'product_id' => $productId,
                'product_variant_id' => $variantId
            ]);

            if (!$stock->exists) {
                $stock->quantity = 0;
                $stock->batch_number = $request->batch_number;
                $stock->expiry_date = $request->expiry_date;
                $stock->save();
            }

            // Update stock based on operation type
            $beforeQuantity = $stock->quantity;
            $afterQuantity = $beforeQuantity;

            switch ($type) {
                case 'add':
                    $afterQuantity = $beforeQuantity + $quantity;
                    $stock->quantity = $afterQuantity;
                    break;

                case 'subtract':
                    if ($beforeQuantity < $quantity) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cannot subtract more than available stock'
                        ], 422);
                    }
                    $afterQuantity = $beforeQuantity - $quantity;
                    $stock->quantity = $afterQuantity;
                    break;

                case 'set':
                    $afterQuantity = $quantity;
                    $stock->quantity = $afterQuantity;
                    break;
            }

            // Save stock
            $stock->save();

            // Create inventory transaction
            $transactionType = $type === 'add' ? 'purchase' : ($type === 'subtract' ? 'adjustment' : 'adjustment');

            InventoryTransaction::create([
                'type' => $transactionType,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'quantity' => $type === 'subtract' ? -$quantity : ($type === 'add' ? $quantity : 0),
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $afterQuantity,
                'reference_type' => 'manual_adjustment',
                'remarks' => $remarks,
                'created_by' => $request->user()->id
            ]);

        } else {
            // Main product stock
            $stock = ProductStock::firstOrNew([
                'product_id' => $productId,
                'product_variant_id' => null
            ]);

            if (!$stock->exists) {
                $stock->quantity = 0;
                $stock->batch_number = $request->batch_number;
                $stock->expiry_date = $request->expiry_date;
                $stock->save();
            }

            // Update stock based on operation type
            $beforeQuantity = $stock->quantity;
            $afterQuantity = $beforeQuantity;

            switch ($type) {
                case 'add':
                    $afterQuantity = $beforeQuantity + $quantity;
                    $stock->quantity = $afterQuantity;
                    break;

                case 'subtract':
                    if ($beforeQuantity < $quantity) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cannot subtract more than available stock'
                        ], 422);
                    }
                    $afterQuantity = $beforeQuantity - $quantity;
                    $stock->quantity = $afterQuantity;
                    break;

                case 'set':
                    $afterQuantity = $quantity;
                    $stock->quantity = $afterQuantity;
                    break;
            }

            // Save stock
            $stock->save();

            // Create inventory transaction
            $transactionType = $type === 'add' ? 'purchase' : ($type === 'subtract' ? 'adjustment' : 'adjustment');

            InventoryTransaction::create([
                'type' => $transactionType,
                'product_id' => $productId,
                'quantity' => $type === 'subtract' ? -$quantity : ($type === 'add' ? $quantity : 0),
                'before_quantity' => $beforeQuantity,
                'after_quantity' => $afterQuantity,
                'reference_type' => 'manual_adjustment',
                'remarks' => $remarks,
                'created_by' => $request->user()->id
            ]);
        }

        // Load product with updated stocks
        $product->load(['stocks', 'variants.stocks']);
        $product->current_stock = $product->getCurrentStock();

        return response()->json([
            'success' => true,
            'message' => 'Stock updated successfully',
            'data' => [
                'product' => $product
            ]
        ]);
    }

    /**
     * Get inventory statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInventoryStats()
    {
        // Get all products
        $products = Product::with(['stocks', 'variants.stocks'])->get();

        // Calculate stats
        $totalProducts = $products->count();
        $totalStock = 0;
        $outOfStockCount = 0;
        $lowStockCount = 0;

        foreach ($products as $product) {
            $stockCount = $product->getCurrentStock();
            $totalStock += $stockCount;

            if ($stockCount <= 0) {
                $outOfStockCount++;
            } else if ($product->isLowStock()) {
                $lowStockCount++;
            }
        }

        // Get recent inventory transactions
        $recentTransactions = InventoryTransaction::with(['product', 'variant', 'createdBy'])
            ->latest()
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => [
                    'total_products' => $totalProducts,
                    'total_stock' => $totalStock,
                    'out_of_stock' => $outOfStockCount,
                    'low_stock' => $lowStockCount,
                    'in_stock' => $totalProducts - $outOfStockCount,
                ],
                'recent_transactions' => $recentTransactions
            ]
        ]);
    }
}
