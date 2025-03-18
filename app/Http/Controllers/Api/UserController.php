<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Address;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    /**
     * Get user addresses
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAddresses(Request $request)
    {
        $user = $request->user();
        $addresses = $user->addresses()->orderBy('is_default', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'addresses' => $addresses
            ]
        ]);
    }

    /**
     * Add a new address
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addAddress(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address_line' => 'required|string|max:255',
            'area' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'landmark' => 'nullable|string|max:255',
            'is_default' => 'nullable|boolean',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // If this is the first address or is set as default, unset previous default
        if ($request->input('is_default', false) || $user->addresses()->count() === 0) {
            $user->addresses()->update(['is_default' => false]);
            $request->merge(['is_default' => true]);
        }

        $address = $user->addresses()->create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Address added successfully',
            'data' => [
                'address' => $address
            ]
        ], 201);
    }

    /**
     * Update an existing address
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateAddress(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'address_line' => 'required|string|max:255',
            'area' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'landmark' => 'nullable|string|max:255',
            'is_default' => 'nullable|boolean',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $address = $user->addresses()->find($id);

        if (!$address) {
            return response()->json([
                'success' => false,
                'message' => 'Address not found'
            ], 404);
        }

        // If setting this address as default, unset previous default
        if ($request->input('is_default', false) && !$address->is_default) {
            $user->addresses()->update(['is_default' => false]);
        }

        $address->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Address updated successfully',
            'data' => [
                'address' => $address->fresh()
            ]
        ]);
    }

    /**
     * Delete an address
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteAddress(Request $request, $id)
    {
        $user = $request->user();
        $address = $user->addresses()->find($id);

        if (!$address) {
            return response()->json([
                'success' => false,
                'message' => 'Address not found'
            ], 404);
        }

        // If deleting the default address, set another address as default if available
        if ($address->is_default) {
            $anotherAddress = $user->addresses()->where('id', '!=', $id)->first();
            if ($anotherAddress) {
                $anotherAddress->update(['is_default' => true]);
            }
        }

        $address->delete();

        return response()->json([
            'success' => true,
            'message' => 'Address deleted successfully'
        ]);
    }

    /**
     * Set an address as default
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function setDefaultAddress(Request $request, $id)
    {
        $user = $request->user();
        $address = $user->addresses()->find($id);

        if (!$address) {
            return response()->json([
                'success' => false,
                'message' => 'Address not found'
            ], 404);
        }

        // Unset all addresses as default
        $user->addresses()->update(['is_default' => false]);

        // Set the specified address as default
        $address->update(['is_default' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Default address set successfully',
            'data' => [
                'address' => $address->fresh()
            ]
        ]);
    }

    /**
     * Register device token for push notifications
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function registerDeviceToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'device_id' => 'nullable|string',
            'device_type' => 'required|in:android,ios,web',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Check if token already exists for this user
        $existingToken = $user->deviceTokens()
            ->where('token', $request->token)
            ->first();

        if ($existingToken) {
            $existingToken->update([
                'device_id' => $request->device_id,
                'device_type' => $request->device_type,
                'is_active' => true
            ]);

            $deviceToken = $existingToken;
        } else {
            // Create new token
            $deviceToken = $user->deviceTokens()->create([
                'token' => $request->token,
                'device_id' => $request->device_id,
                'device_type' => $request->device_type,
                'is_active' => true
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Device token registered successfully',
            'data' => [
                'device_token' => $deviceToken
            ]
        ]);
    }

    /**
     * Unregister device token
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function unregisterDeviceToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Find and deactivate token
        $deviceToken = $user->deviceTokens()
            ->where('token', $request->token)
            ->first();

        if ($deviceToken) {
            $deviceToken->update(['is_active' => false]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Device token unregistered successfully'
        ]);
    }

    /**
     * Get user's order history
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrderHistory(Request $request)
    {
        $user = $request->user();

        $perPage = $request->input('per_page', 10);
        $status = $request->input('status');

        $ordersQuery = $user->orders()->with(['items', 'deliveryArea', 'deliverySlot']);

        if ($status) {
            $ordersQuery->where('status', $status);
        }

        $orders = $ordersQuery->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'orders' => $orders
            ]
        ]);
    }

    /**
     * Get user's wishlist
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWishlist(Request $request)
    {
        $user = $request->user();
        $wishlist = $user->wishlists()->with('product')->latest()->get();

        return response()->json([
            'success' => true,
            'data' => [
                'wishlist' => $wishlist
            ]
        ]);
    }

    /**
     * Add product to wishlist
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addToWishlist(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $productId = $request->product_id;

        // Check if product is already in wishlist
        $existingWishlist = $user->wishlists()
            ->where('product_id', $productId)
            ->first();

        if ($existingWishlist) {
            return response()->json([
                'success' => true,
                'message' => 'Product is already in your wishlist'
            ]);
        }

        // Add to wishlist
        $wishlist = $user->wishlists()->create([
            'product_id' => $productId
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product added to wishlist',
            'data' => [
                'wishlist' => $wishlist
            ]
        ], 201);
    }

    /**
     * Remove product from wishlist
     *
     * @param Request $request
     * @param int $productId
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeFromWishlist(Request $request, $productId)
    {
        $user = $request->user();

        $wishlist = $user->wishlists()
            ->where('product_id', $productId)
            ->first();

        if (!$wishlist) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found in wishlist'
            ], 404);
        }

        $wishlist->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product removed from wishlist'
        ]);
    }

    /**
     * Subscribe to newsletter
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function subscribeNewsletter(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if email already subscribed
        $existingSubscription = \App\Models\Subscription::where('email', $request->email)->first();

        if ($existingSubscription) {
            if ($existingSubscription->is_active) {
                return response()->json([
                    'success' => true,
                    'message' => 'You are already subscribed to our newsletter'
                ]);
            } else {
                $existingSubscription->update(['is_active' => true]);
                return response()->json([
                    'success' => true,
                    'message' => 'Your subscription has been reactivated'
                ]);
            }
        }

        // Create new subscription
        $subscription = \App\Models\Subscription::create([
            'email' => $request->email,
            'phone' => $request->user() ? $request->user()->phone : null,
            'is_active' => true,
            'source' => 'website'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Thank you for subscribing to our newsletter',
            'data' => [
                'subscription' => $subscription
            ]
        ], 201);
    }

    /**
     * Unsubscribe from newsletter
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function unsubscribeNewsletter(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $subscription = \App\Models\Subscription::where('email', $request->email)->first();

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'This email is not subscribed to our newsletter'
            ], 404);
        }

        $subscription->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'You have been unsubscribed from our newsletter'
        ]);
    }

    /**
     * Submit a contact message
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function submitContactMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'required|string|max:20',
            'subject' => 'required|string|max:255',
            'message' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $contactMessage = \App\Models\ContactMessage::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'subject' => $request->subject,
            'message' => $request->message,
            'is_read' => false,
            'status' => 'pending'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Your message has been submitted successfully. We will get back to you soon.',
            'data' => [
                'contact_message' => $contactMessage
            ]
        ], 201);
    }
}
