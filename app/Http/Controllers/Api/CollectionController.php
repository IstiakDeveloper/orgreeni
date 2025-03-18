<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CollectionController extends Controller
{
    /**
     * Get all active collections
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $cacheKey = 'collections_all';

        $collections = Cache::remember($cacheKey, 60 * 30, function () {
            return ProductCollection::where('is_active', true)
                ->orderBy('order')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'collections' => $collections
            ]
        ]);
    }

    /**
     * Get collection with its products
     *
     * @param string $slug
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($slug)
    {
        $cacheKey = 'collection_' . $slug;

        $collection = Cache::remember($cacheKey, 60 * 15, function () use ($slug) {
            return ProductCollection::where('slug', $slug)
                ->where('is_active', true)
                ->with([
                    'products' => function ($query) {
                        $query->where('status', 'active')
                            ->with(['images', 'category', 'brand']);
                    }
                ])
                ->first();
        });

        if (!$collection) {
            return response()->json([
                'success' => false,
                'message' => 'Collection not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'collection' => $collection
            ]
        ]);
    }
}
