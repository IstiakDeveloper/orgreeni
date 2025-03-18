<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    /**
     * Get all settings grouped by type
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $settings = Setting::all()->groupBy('group');

        // Format settings into key-value pairs for each group
        $formattedSettings = [];

        foreach ($settings as $group => $groupSettings) {
            $formattedSettings[$group] = [];

            foreach ($groupSettings as $setting) {
                $formattedSettings[$group][$setting->key] = $setting->value;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $formattedSettings
            ]
        ]);
    }

    /**
     * Get settings by group
     *
     * @param string $group
     * @return \Illuminate\Http\JsonResponse
     */
    public function getByGroup($group)
    {
        $settings = Setting::where('group', $group)->get();

        // Format settings into key-value pairs
        $formattedSettings = [];

        foreach ($settings as $setting) {
            $formattedSettings[$setting->key] = $setting->value;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'group' => $group,
                'settings' => $formattedSettings
            ]
        ]);
    }

    /**
     * Update settings
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'group' => 'required|string|max:50',
            'settings' => 'required|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $group = $request->group;
        $settings = $request->settings;

        // Update or create each setting
        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(
                ['group' => $group, 'key' => $key],
                ['value' => $value]
            );
        }

        // Clear cache for site settings
        Cache::forget('site_settings_public');
        Cache::put('settings_updated_at', now());

        // Log admin activity
        if ($request->user()) {
            AdminActivity::create([
                'user_id' => $request->user()->id,
                'activity_type' => 'settings_updated',
                'properties' => [
                    'group' => $group,
                    'updated_keys' => array_keys($settings)
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
            'data' => [
                'group' => $group,
                'settings' => $settings
            ]
        ]);
    }

    /**
     * Upload site logo
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadLogo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'site_logo' => 'required|image|mimes:jpeg,png,jpg,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Get current logo
        $currentLogo = Setting::where('group', 'general')
            ->where('key', 'site_logo')
            ->first();

        // Delete old logo if exists
        if ($currentLogo && !empty($currentLogo->value)) {
            Storage::delete('public/logo/' . $currentLogo->value);
        }

        // Upload new logo
        $logo = $request->file('site_logo');
        $filename = 'site_logo_' . time() . '.' . $logo->getClientOriginalExtension();
        $logo->storeAs('public/logo', $filename);

        // Save logo setting
        Setting::updateOrCreate(
            ['group' => 'general', 'key' => 'site_logo'],
            ['value' => $filename]
        );

        // Clear cache
        Cache::forget('site_settings_public');

        // Log admin activity
        if ($request->user()) {
            AdminActivity::create([
                'user_id' => $request->user()->id,
                'activity_type' => 'logo_updated',
                'properties' => [
                    'filename' => $filename
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Site logo uploaded successfully',
            'data' => [
                'logo' => $filename,
                'logo_url' => url('storage/logo/' . $filename)
            ]
        ]);
    }

    /**
     * Upload site favicon
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadFavicon(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'favicon' => 'required|image|mimes:ico,png|max:1024',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Get current favicon
        $currentFavicon = Setting::where('group', 'general')
            ->where('key', 'favicon')
            ->first();

        // Delete old favicon if exists
        if ($currentFavicon && !empty($currentFavicon->value)) {
            Storage::delete('public/logo/' . $currentFavicon->value);
        }

        // Upload new favicon
        $favicon = $request->file('favicon');
        $filename = 'favicon_' . time() . '.' . $favicon->getClientOriginalExtension();
        $favicon->storeAs('public/logo', $filename);

        // Save favicon setting
        Setting::updateOrCreate(
            ['group' => 'general', 'key' => 'favicon'],
            ['value' => $filename]
        );

        // Clear cache
        Cache::forget('site_settings_public');

        // Log admin activity
        if ($request->user()) {
            AdminActivity::create([
                'user_id' => $request->user()->id,
                'activity_type' => 'favicon_updated',
                'properties' => [
                    'filename' => $filename
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Favicon uploaded successfully',
            'data' => [
                'favicon' => $filename,
                'favicon_url' => url('storage/logo/' . $filename)
            ]
        ]);
    }

    /**
     * Get setting definitions
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDefinitions()
    {
        // Define available setting groups and their settings
        $settingDefinitions = [
            'general' => [
                [
                    'key' => 'site_name',
                    'label' => 'Site Name',
                    'type' => 'text',
                    'required' => true
                ],
                [
                    'key' => 'site_tagline',
                    'label' => 'Site Tagline',
                    'type' => 'text'
                ],
                [
                    'key' => 'site_description',
                    'label' => 'Site Description',
                    'type' => 'textarea'
                ],
                [
                    'key' => 'contact_email',
                    'label' => 'Contact Email',
                    'type' => 'email'
                ],
                [
                    'key' => 'contact_phone',
                    'label' => 'Contact Phone',
                    'type' => 'text'
                ],
                [
                    'key' => 'contact_address',
                    'label' => 'Contact Address',
                    'type' => 'textarea'
                ],
                [
                    'key' => 'about_short',
                    'label' => 'Short About Text',
                    'type' => 'textarea'
                ],
                [
                    'key' => 'footer_info',
                    'label' => 'Footer Information',
                    'type' => 'textarea'
                ]
            ],
            'social' => [
                [
                    'key' => 'social_facebook',
                    'label' => 'Facebook URL',
                    'type' => 'url'
                ],
                [
                    'key' => 'social_instagram',
                    'label' => 'Instagram URL',
                    'type' => 'url'
                ],
                [
                    'key' => 'social_twitter',
                    'label' => 'Twitter URL',
                    'type' => 'url'
                ],
                [
                    'key' => 'social_youtube',
                    'label' => 'YouTube URL',
                    'type' => 'url'
                ]
            ],
            'business' => [
                [
                    'key' => 'business_hours',
                    'label' => 'Business Hours',
                    'type' => 'text'
                ],
                [
                    'key' => 'business_days',
                    'label' => 'Business Days',
                    'type' => 'text'
                ],
                [
                    'key' => 'min_order_amount',
                    'label' => 'Minimum Order Amount',
                    'type' => 'number'
                ],
                [
                    'key' => 'delivery_charge_info',
                    'label' => 'Delivery Charge Information',
                    'type' => 'textarea'
                ],
                [
                    'key' => 'vat_percentage',
                    'label' => 'VAT Percentage',
                    'type' => 'number'
                ]
            ],
            'order' => [
                [
                    'key' => 'advance_order_days',
                    'label' => 'Advance Order Days',
                    'type' => 'number'
                ],
                [
                    'key' => 'delivery_days',
                    'label' => 'Delivery Days (comma separated)',
                    'type' => 'text'
                ],
                [
                    'key' => 'order_prefix',
                    'label' => 'Order Number Prefix',
                    'type' => 'text'
                ]
            ],
            'notification' => [
                [
                    'key' => 'admin_new_order_notification',
                    'label' => 'Admin New Order Notification',
                    'type' => 'boolean'
                ],
                [
                    'key' => 'customer_order_confirmation',
                    'label' => 'Customer Order Confirmation Notification',
                    'type' => 'boolean'
                ],
                [
                    'key' => 'order_status_notification',
                    'label' => 'Order Status Change Notification',
                    'type' => 'boolean'
                ]
            ],
            'payment' => [
                [
                    'key' => 'bkash_enabled',
                    'label' => 'Enable bKash Payment',
                    'type' => 'boolean'
                ],
                [
                    'key' => 'bkash_number',
                    'label' => 'bKash Number',
                    'type' => 'text'
                ],
                [
                    'key' => 'nagad_enabled',
                    'label' => 'Enable Nagad Payment',
                    'type' => 'boolean'
                ],
                [
                    'key' => 'nagad_number',
                    'label' => 'Nagad Number',
                    'type' => 'text'
                ],
                [
                    'key' => 'rocket_enabled',
                    'label' => 'Enable Rocket Payment',
                    'type' => 'boolean'
                ],
                [
                    'key' => 'rocket_number',
                    'label' => 'Rocket Number',
                    'type' => 'text'
                ],
                [
                    'key' => 'cod_enabled',
                    'label' => 'Enable Cash On Delivery',
                    'type' => 'boolean'
                ]
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'setting_definitions' => $settingDefinitions
            ]
        ]);
    }
}
