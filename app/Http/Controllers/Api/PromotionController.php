<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\ComboOffer;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    /**
     * Get all active promotions
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $promotions = Promotion::where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>=', now())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'promotions' => $promotions
            ]
        ]);
    }

    /**
     * Get promotion details with products
     *
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $promotion = Promotion::where('id', $id)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>=', now())
            ->with([
                'products.product.images',
                'products.product.category'
            ])
            ->first();

        if (!$promotion) {
            return response()->json([
                'success' => false,
                'message' => 'Promotion not found or expired'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'promotion' => $promotion
            ]
        ]);
    }

    /**
     * Get products by promotion type
     *
     * @param string $type
     * @return \Illuminate\Http\JsonResponse
     */
    public function getByType($type)
    {
        $validTypes = ['flash_sale', 'special_offer', 'deal_of_day', 'combo', 'seasonal'];

        if (!in_array($type, $validTypes)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid promotion type'
            ], 400);
        }

        $promotion = Promotion::where('type', $type)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>=', now())
            ->with([
                'products.product.images',
                'products.product.category'
            ])
            ->first();

        if (!$promotion) {
            return response()->json([
                'success' => false,
                'message' => 'No active promotion found for this type'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'promotion' => $promotion
            ]
        ]);
    }

    /**
     * Get all active combo offers
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function comboOffers()
    {
        $comboOffers = ComboOffer::where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>=', now())
            ->orderBy('order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'combo_offers' => $comboOffers
            ]
        ]);
    }

    /**
     * Get combo offer details
     *
     * @param string $slug
     * @return \Illuminate\Http\JsonResponse
     */
    public function comboOfferDetails($slug)
    {
        $comboOffer = ComboOffer::where('slug', $slug)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>=', now())
            ->with([
                'products.product.images',
                'products.variant'
            ])
            ->first();

        if (!$comboOffer) {
            return response()->json([
                'success' => false,
                'message' => 'Combo offer not found or expired'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'combo_offer' => $comboOffer
            ]
        ]);
    }
}
