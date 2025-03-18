<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    /**
     * Get all categories
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Cache key based on whether we want parent categories only or all categories
        $parentOnly = $request->input('parent_only', false);
        $cacheKey = 'categories_' . ($parentOnly ? 'parent_only' : 'all');

        // Get categories from cache or database
        $categories = Cache::remember($cacheKey, 60 * 60, function () use ($parentOnly) {
            $query = Category::where('is_active', true)
                ->orderBy('order')
                ->with('products');

            if ($parentOnly) {
                $query->whereNull('parent_id');
            }

            return $query->get();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories
            ]
        ]);
    }

    /**
     * Get a specific category with its children
     *
     * @param string $slug
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($slug)
    {
        $cacheKey = 'category_' . $slug;

        $category = Cache::remember($cacheKey, 60 * 30, function () use ($slug) {
            return Category::where('slug', $slug)
                ->where('is_active', true)
                ->with([
                    'children' => function ($query) {
                        $query->where('is_active', true)->orderBy('order');
                    }
                ])
                ->first();
        });

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'category' => $category
            ]
        ]);
    }

    /**
     * Get products from a specific category
     *
     * @param Request $request
     * @param string $slug
     * @return \Illuminate\Http\JsonResponse
     */
    public function products(Request $request, $slug)
    {
        $category = Category::where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }

        // Get category and its children ids
        $categoryIds = [$category->id];
        $children = Category::where('parent_id', $category->id)
            ->where('is_active', true)
            ->get();

        foreach ($children as $child) {
            $categoryIds[] = $child->id;
        }

        // Pagination parameters
        $perPage = $request->input('per_page', 20);
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        // Filter parameters
        $minPrice = $request->input('min_price');
        $maxPrice = $request->input('max_price');
        $brands = $request->input('brands'); // Comma-separated brand IDs

        // Start the query
        $productsQuery = \App\Models\Product::whereIn('category_id', $categoryIds)
            ->where('status', 'active')
            ->with(['images', 'variants', 'brand'])
            ->withCount('reviews');

        // Apply price filters if provided
        if ($minPrice !== null) {
            $productsQuery->where('sale_price', '>=', $minPrice);
        }

        if ($maxPrice !== null) {
            $productsQuery->where('sale_price', '<=', $maxPrice);
        }

        // Apply brand filter if provided
        if ($brands) {
            $brandIds = explode(',', $brands);
            $productsQuery->whereIn('brand_id', $brandIds);
        }

        // Apply sorting
        switch ($sortBy) {
            case 'price_low':
                $productsQuery->orderBy('sale_price', 'asc');
                break;
            case 'price_high':
                $productsQuery->orderBy('sale_price', 'desc');
                break;
            case 'name':
                $productsQuery->orderBy('name', 'asc');
                break;
            case 'popularity':
                $productsQuery->orderBy('is_popular', 'desc')->orderBy('created_at', 'desc');
                break;
            default:
                $productsQuery->orderBy($sortBy, $sortOrder);
        }

        // Get paginated results
        $products = $productsQuery->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'category' => $category,
                'products' => $products
            ]
        ]);
    }
}
