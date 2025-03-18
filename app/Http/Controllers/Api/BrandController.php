<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BrandController extends Controller
{
    /**
     * Get all brands
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Debug করুন
        \Log::info('Admin brands index method called');

        $brands = Brand::orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'brands' => $brands
            ]
        ]);
    }

    /**
     * Get a specific brand with its products
     *
     * @param Request $request
     * @param string $slug
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $slug)
    {
        $brand = Brand::where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (!$brand) {
            return response()->json([
                'success' => false,
                'message' => 'Brand not found'
            ], 404);
        }

        // Pagination parameters
        $perPage = $request->input('per_page', 20);
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        // Filter parameters
        $minPrice = $request->input('min_price');
        $maxPrice = $request->input('max_price');
        $categoryId = $request->input('category_id');

        // Start products query
        $productsQuery = $brand->products()
            ->where('status', 'active')
            ->with(['images', 'category']);

        // Apply category filter if provided
        if ($categoryId) {
            $productsQuery->where('category_id', $categoryId);
        }

        // Apply price filters if provided
        if ($minPrice !== null) {
            $productsQuery->where('sale_price', '>=', $minPrice);
        }

        if ($maxPrice !== null) {
            $productsQuery->where('sale_price', '<=', $maxPrice);
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
                'brand' => $brand,
                'products' => $products
            ]
        ]);
    }
}
