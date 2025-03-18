<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * Get featured products
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFeatured()
    {
        $cacheKey = 'products_featured';

        $featuredProducts = Cache::remember($cacheKey, 60 * 30, function () {
            return Product::where('status', 'active')
                ->where('is_featured', true)
                ->with(['images', 'category', 'brand', 'unit'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $featuredProducts
            ]
        ]);
    }

    /**
     * Get popular products
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPopular()
    {
        $cacheKey = 'products_popular';

        $popularProducts = Cache::remember($cacheKey, 60 * 30, function () {
            return Product::where('status', 'active')
                ->where('is_popular', true)
                ->with(['images', 'category', 'brand', 'unit'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $popularProducts
            ]
        ]);
    }

    /**
     * Get products with discount
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDiscounted()
    {
        $cacheKey = 'products_discounted';

        $discountedProducts = Cache::remember($cacheKey, 60 * 15, function () {
            return Product::where('status', 'active')
                ->where('discount_percentage', '>', 0)
                ->with(['images', 'category', 'brand', 'unit'])
                ->orderBy('discount_percentage', 'desc')
                ->limit(10)
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $discountedProducts
            ]
        ]);
    }

    /**
     * Get new arrivals
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNewArrivals()
    {
        $cacheKey = 'products_new_arrivals';

        $newArrivals = Cache::remember($cacheKey, 60 * 15, function () {
            return Product::where('status', 'active')
                ->with(['images', 'category', 'brand', 'unit'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $newArrivals
            ]
        ]);
    }

    /**
     * Get product details
     *
     * @param string $slug
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($slug)
    {
        $cacheKey = 'product_' . $slug;

        $product = Cache::remember($cacheKey, 60 * 10, function () use ($slug) {
            return Product::where('slug', $slug)
                ->where('status', 'active')
                ->with([
                    'images',
                    'category',
                    'brand',
                    'unit',
                    'variants',
                    'reviews' => function ($query) {
                        $query->where('is_approved', true)
                            ->latest()
                            ->limit(5);
                    },
                    'reviews.user:id,name,profile_photo',
                    'attributeValues.attribute'
                ])
                ->withCount(['reviews as review_count' => function ($query) {
                    $query->where('is_approved', true);
                }])
                ->withAvg(['reviews as average_rating' => function ($query) {
                    $query->where('is_approved', true);
                }], 'rating')
                ->first();
        });

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Get related products
        $relatedProducts = Cache::remember('related_' . $product->id, 60 * 30, function () use ($product) {
            return Product::where('status', 'active')
                ->where('id', '!=', $product->id)
                ->where(function ($query) use ($product) {
                    $query->where('category_id', $product->category_id)
                        ->orWhere('brand_id', $product->brand_id);
                })
                ->with(['images', 'category'])
                ->inRandomOrder()
                ->limit(6)
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'product' => $product,
                'related_products' => $relatedProducts
            ]
        ]);
    }

    /**
     * Search products
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'keyword' => 'required|string|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $keyword = $request->input('keyword');
        $perPage = $request->input('per_page', 20);

        // Create search query
        $productsQuery = Product::where('status', 'active')
            ->where(function ($query) use ($keyword) {
                $query->where('name', 'like', "%{$keyword}%")
                    ->orWhere('name_bn', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%")
                    ->orWhere('sku', 'like', "%{$keyword}%")
                    ->orWhere('barcode', 'like', "%{$keyword}%")
                    ->orWhereHas('category', function ($q) use ($keyword) {
                        $q->where('name', 'like', "%{$keyword}%")
                            ->orWhere('name_bn', 'like', "%{$keyword}%");
                    })
                    ->orWhereHas('brand', function ($q) use ($keyword) {
                        $q->where('name', 'like', "%{$keyword}%")
                            ->orWhere('name_bn', 'like', "%{$keyword}%");
                    });
            })
            ->with(['images', 'category', 'brand']);

        // Get results
        $products = $productsQuery->paginate($perPage);

        // Log search
        $this->logSearch($request, $keyword, $products->total());

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $products
            ]
        ]);
    }

    /**
     * Get product reviews
     *
     * @param Request $request
     * @param int $productId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getReviews(Request $request, $productId)
    {
        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $perPage = $request->input('per_page', 10);

        $reviews = $product->reviews()
            ->where('is_approved', true)
            ->with('user:id,name,profile_photo')
            ->latest()
            ->paginate($perPage);

        $averageRating = $product->reviews()
            ->where('is_approved', true)
            ->avg('rating');

        $ratingCounts = $product->reviews()
            ->where('is_approved', true)
            ->select('rating', DB::raw('count(*) as count'))
            ->groupBy('rating')
            ->get()
            ->pluck('count', 'rating')
            ->toArray();

        // Fill in missing rating counts
        for ($i = 1; $i <= 5; $i++) {
            if (!isset($ratingCounts[$i])) {
                $ratingCounts[$i] = 0;
            }
        }

        // Sort by rating value
        ksort($ratingCounts);

        return response()->json([
            'success' => true,
            'data' => [
                'reviews' => $reviews,
                'average_rating' => round($averageRating, 1),
                'rating_counts' => $ratingCounts
            ]
        ]);
    }

    /**
     * Submit a product review
     *
     * @param Request $request
     * @param int $productId
     * @return \Illuminate\Http\JsonResponse
     */
    public function submitReview(Request $request, $productId)
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string',
            'order_id' => 'nullable|exists:orders,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $user = $request->user();

        // Check if user has already reviewed this product
        $existingReview = $product->reviews()
            ->where('user_id', $user->id)
            ->first();

        if ($existingReview) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reviewed this product'
            ], 422);
        }

        // Check if order_id is provided and valid
        if ($request->has('order_id')) {
            $order = \App\Models\Order::find($request->order_id);

            // Check if order belongs to user
            if (!$order || $order->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid order ID'
                ], 422);
            }

            // Check if product was purchased in this order
            $orderItem = $order->items()->where('product_id', $productId)->first();

            if (!$orderItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'This product was not purchased in the specified order'
                ], 422);
            }
        }

        // Create review
        $review = $product->reviews()->create([
            'user_id' => $user->id,
            'order_id' => $request->order_id,
            'rating' => $request->rating,
            'comment' => $request->comment,
            'is_approved' => false // Require admin approval
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your review. It will be visible after approval.',
            'data' => [
                'review' => $review
            ]
        ], 201);
    }

    /**
     * Log the search query
     *
     * @param Request $request
     * @param string $keyword
     * @param int $resultsCount
     * @return void
     */
    private function logSearch(Request $request, $keyword, $resultsCount)
    {
        try {
            ProductSearch::create([
                'keyword' => $keyword,
                'user_id' => $request->user() ? $request->user()->id : null,
                'ip_address' => $request->ip(),
                'results_count' => $resultsCount
            ]);
        } catch (\Exception $e) {
            // Just log to error log if there's an issue with logging
            \Illuminate\Support\Facades\Log::error('Failed to log search: ' . $e->getMessage());
        }
    }
}
