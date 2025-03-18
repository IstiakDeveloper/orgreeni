<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Page;
use App\Models\Faq;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ContentController extends Controller
{
    /**
     * Get all active banners
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBanners(Request $request)
    {
        $position = $request->input('position', 'home_top');
        $cacheKey = 'banners_' . $position;

        $banners = Cache::remember($cacheKey, 60 * 30, function () use ($position) {
            return Banner::where('position', $position)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('starts_at')
                        ->orWhere('starts_at', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', now());
                })
                ->orderBy('order')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'banners' => $banners
            ]
        ]);
    }

    /**
     * Get page content by slug
     *
     * @param string $slug
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPage($slug)
    {
        $cacheKey = 'page_' . $slug;

        $page = Cache::remember($cacheKey, 60 * 60 * 24, function () use ($slug) {
            return Page::where('slug', $slug)
                ->where('is_active', true)
                ->first();
        });

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'Page not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'page' => $page
            ]
        ]);
    }

    /**
     * Get FAQs
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFaqs(Request $request)
    {
        $category = $request->input('category');
        $cacheKey = 'faqs_' . ($category ? $category : 'all');

        $faqsQuery = Cache::remember($cacheKey, 60 * 60 * 24, function () use ($category) {
            $query = Faq::where('is_active', true)
                ->orderBy('order');

            if ($category) {
                $query->where('category', $category);
            }

            return $query->get();
        });

        // Group by category for better display
        $groupedFaqs = $faqsQuery->groupBy('category');

        return response()->json([
            'success' => true,
            'data' => [
                'faqs' => $groupedFaqs
            ]
        ]);
    }

    /**
     * Get all footer pages
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFooterPages()
    {
        $cacheKey = 'footer_pages';

        $footerPages = Cache::remember($cacheKey, 60 * 60 * 24, function () {
            return Page::where('is_active', true)
                ->where('show_in_footer', true)
                ->orderBy('order')
                ->select('title', 'title_bn', 'slug')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'footer_pages' => $footerPages
            ]
        ]);
    }

    /**
     * Get all header pages
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHeaderPages()
    {
        $cacheKey = 'header_pages';

        $headerPages = Cache::remember($cacheKey, 60 * 60 * 24, function () {
            return Page::where('is_active', true)
                ->where('show_in_header', true)
                ->orderBy('order')
                ->select('title', 'title_bn', 'slug')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'header_pages' => $headerPages
            ]
        ]);
    }

    /**
     * Get site settings for public display
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSiteSettings()
    {
        $cacheKey = 'site_settings_public';

        $settings = Cache::remember($cacheKey, 60 * 60 * 24, function () {
            // Get only settings that should be exposed to the public
            $publicSettings = [
                'site_name',
                'site_tagline',
                'site_description',
                'contact_email',
                'contact_phone',
                'contact_address',
                'social_facebook',
                'social_instagram',
                'social_youtube',
                'social_twitter',
                'min_order_amount',
                'delivery_charge_info',
                'business_hours',
                'business_days',
                'site_logo',
                'footer_info',
                'about_short'
            ];

            $result = [];

            foreach ($publicSettings as $key) {
                $result[$key] = Setting::getValue($key, '', 'general');
            }

            return $result;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $settings
            ]
        ]);
    }
}
