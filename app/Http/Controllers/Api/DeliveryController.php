<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryArea;
use App\Models\DeliverySlot;
use App\Models\ShippingMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DeliveryController extends Controller
{
    /**
     * Get all delivery areas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDeliveryAreas()
    {
        $cacheKey = 'delivery_areas';

        $areas = Cache::remember($cacheKey, 60 * 60, function () {
            return DeliveryArea::where('is_active', true)
                ->orderBy('name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'delivery_areas' => $areas
            ]
        ]);
    }

    /**
     * Get specific delivery area details
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDeliveryAreaDetails($id)
    {
        $area = DeliveryArea::where('is_active', true)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'delivery_area' => $area
            ]
        ]);
    }

    /**
     * Get delivery slots for specific date
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDeliverySlots(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
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
     * Get available delivery dates
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDeliveryDates()
    {
        // Get number of days in advance delivery is available
        $advanceDays = (int) \App\Models\Setting::getValue('advance_order_days', 7);

        $dates = [];
        $currentDate = Carbon::today();

        // Check which dates have available slots
        for ($i = 0; $i < $advanceDays; $i++) {
            $checkDate = $currentDate->copy()->addDays($i);

            // Skip days when delivery is not available
            $dayOfWeek = strtolower($checkDate->format('l'));
            $deliveryDaysStr = \App\Models\Setting::getValue('delivery_days', 'monday,tuesday,wednesday,thursday,friday,saturday,sunday');
            $deliveryDays = explode(',', strtolower($deliveryDaysStr));

            if (!in_array($dayOfWeek, $deliveryDays)) {
                continue;
            }

            // Check if any slots are available on this date
            $hasAvailableSlots = false;
            $slots = DeliverySlot::where('is_active', true)->get();

            foreach ($slots as $slot) {
                if ($slot->getRemainingSlots($checkDate) > 0) {
                    $hasAvailableSlots = true;
                    break;
                }
            }

            if ($hasAvailableSlots) {
                $dates[] = [
                    'date' => $checkDate->format('Y-m-d'),
                    'day_name' => $checkDate->format('l'),
                    'day_name_bn' => $this->getDayNameInBengali($checkDate->format('l')),
                    'formatted_date' => $checkDate->format('d M, Y')
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'delivery_dates' => $dates
            ]
        ]);
    }

    /**
     * Get shipping methods
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShippingMethods()
    {
        $cacheKey = 'shipping_methods';

        $methods = Cache::remember($cacheKey, 60 * 60, function () {
            return ShippingMethod::where('is_active', true)
                ->orderBy('cost')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'shipping_methods' => $methods
            ]
        ]);
    }

    /**
     * Calculate shipping cost
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function calculateShipping(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'delivery_area_id' => 'required|exists:delivery_areas,id',
            'order_amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $deliveryArea = DeliveryArea::findOrFail($request->delivery_area_id);
        $orderAmount = $request->order_amount;

        // Check minimum order amount
        if ($orderAmount < $deliveryArea->min_order_amount) {
            return response()->json([
                'success' => false,
                'message' => "This area requires a minimum order of ৳{$deliveryArea->min_order_amount}",
                'data' => [
                    'min_order_amount' => $deliveryArea->min_order_amount
                ]
            ], 422);
        }

        // Calculate shipping cost
        $shippingCost = $deliveryArea->getDeliveryCharge($orderAmount);

        return response()->json([
            'success' => true,
            'data' => [
                'delivery_area' => $deliveryArea,
                'order_amount' => $orderAmount,
                'shipping_cost' => $shippingCost,
                'free_delivery_min_amount' => $deliveryArea->free_delivery_min_amount,
                'is_free_delivery' => $shippingCost === 0
            ]
        ]);
    }

    /**
     * Get day name in Bengali
     *
     * @param string $englishDayName
     * @return string
     */
    private function getDayNameInBengali($englishDayName)
    {
        $dayNames = [
            'Sunday' => 'রবিবার',
            'Monday' => 'সোমবার',
            'Tuesday' => 'মঙ্গলবার',
            'Wednesday' => 'বুধবার',
            'Thursday' => 'বৃহস্পতিবার',
            'Friday' => 'শুক্রবার',
            'Saturday' => 'শনিবার'
        ];

        return $dayNames[$englishDayName] ?? $englishDayName;
    }
}
