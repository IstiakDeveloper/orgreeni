<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\OtpVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Send OTP for registration, login or password reset
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|size:11',
            'type' => 'required|in:registration,login,password_reset'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $phone = $request->input('phone');
        $type = $request->input('type');

        // For registration, check if phone is already registered
        if ($type === 'registration') {
            $existingUser = User::where('phone', $phone)->first();
            if ($existingUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone number already registered'
                ], 422);
            }
        }

        // For login or password reset, check if phone exists
        if (in_array($type, ['login', 'password_reset'])) {
            $existingUser = User::where('phone', $phone)->first();
            if (!$existingUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone number not registered'
                ], 422);
            }
        }

        // Generate OTP
        $otp = OtpVerification::generateOtp();

        // Save OTP
        OtpVerification::create([
            'phone' => $phone,
            'otp' => $otp,
            'type' => $type,
            'expires_at' => Carbon::now()->addMinutes(10),
            'is_used' => false
        ]);

        // In a real application, you would send the OTP via SMS
        // For now, we'll just return it in the response (for development purposes only)

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully',
            'data' => [
                'otp' => $otp, // Remove this in production
                'phone' => $phone,
                'expires_in' => 10 // minutes
            ]
        ]);
    }

    /**
     * Verify OTP and register a new user
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|size:11|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'otp' => 'required|string|size:6',
            'email' => 'nullable|email|unique:users'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verify OTP
        $verification = OtpVerification::where('phone', $request->phone)
            ->where('otp', $request->otp)
            ->where('type', 'registration')
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->latest()
            ->first();

        if (!$verification) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP'
            ], 422);
        }

        // Create user
        $user = User::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone_verified_at' => Carbon::now(),
            'role' => 'customer'
        ]);

        // Mark OTP as used
        $verification->markAsUsed();

        // Generate token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'data' => [
                'user' => $user,
                'token' => $token
            ]
        ], 201);
    }

    /**
     * Login with phone and password
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|size:11',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if user exists
        $user = User::where('phone', $request->phone)->first();

        // Check if user exists and password is correct
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        // Check if user is active
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact support.'
            ], 403);
        }

        // Generate token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Log login activity
        $this->logUserLogin($request, $user, true);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user,
                'token' => $token
            ]
        ]);
    }

    /**
     * Login with OTP
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function loginWithOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|size:11',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verify OTP
        $verification = OtpVerification::where('phone', $request->phone)
            ->where('otp', $request->otp)
            ->where('type', 'login')
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->latest()
            ->first();

        if (!$verification) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP'
            ], 422);
        }

        // Get user
        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Check if user is active
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact support.'
            ], 403);
        }

        // Mark OTP as used
        $verification->markAsUsed();

        // Generate token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Log login activity
        $this->logUserLogin($request, $user, true);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user,
                'token' => $token
            ]
        ]);
    }

    /**
     * Reset password with OTP
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|size:11',
            'otp' => 'required|string|size:6',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verify OTP
        $verification = OtpVerification::where('phone', $request->phone)
            ->where('otp', $request->otp)
            ->where('type', 'password_reset')
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->latest()
            ->first();

        if (!$verification) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP'
            ], 422);
        }

        // Get user
        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        // Mark OTP as used
        $verification->markAsUsed();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successful',
        ]);
    }

    /**
     * Logout user and revoke token
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        // Revoke the token that was used to authenticate the current request
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Successfully logged out'
        ]);
    }

    /**
     * Get authenticated user details
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUser(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $request->user()
            ]
        ]);
    }

    /**
     * Update user profile
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Handle profile photo upload
        if ($request->hasFile('profile_photo')) {
            $profilePhoto = $request->file('profile_photo');
            $filename = time() . '.' . $profilePhoto->getClientOriginalExtension();
            $profilePhoto->storeAs('public/profile_photos', $filename);
            $user->profile_photo = $filename;
        }

        $user->name = $request->name;

        if ($request->has('email')) {
            $user->email = $request->email;
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'user' => $user
            ]
        ]);
    }

    /**
     * Change password
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Check if current password is correct
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect'
            ], 422);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    /**
     * Log user login activity
     *
     * @param Request $request
     * @param User $user
     * @param bool $isSuccessful
     * @param string|null $failureReason
     * @return void
     */
    private function logUserLogin(Request $request, User $user, bool $isSuccessful, string $failureReason = null)
    {
        try {
            $user->loginLogs()->create([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_info' => $this->getDeviceInfo($request->userAgent()),
                'is_successful' => $isSuccessful,
                'failure_reason' => $failureReason,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log user login: ' . $e->getMessage());
        }
    }

    /**
     * Extract device information from user agent
     *
     * @param string|null $userAgent
     * @return string
     */
    private function getDeviceInfo(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'Unknown device';
        }

        $deviceInfo = [];

        // Detect browser
        if (preg_match('/MSIE|Trident/i', $userAgent)) {
            $deviceInfo[] = 'Internet Explorer';
        } elseif (preg_match('/Firefox/i', $userAgent)) {
            $deviceInfo[] = 'Firefox';
        } elseif (preg_match('/Chrome/i', $userAgent)) {
            $deviceInfo[] = 'Chrome';
        } elseif (preg_match('/Safari/i', $userAgent)) {
            $deviceInfo[] = 'Safari';
        } elseif (preg_match('/Opera/i', $userAgent)) {
            $deviceInfo[] = 'Opera';
        } else {
            $deviceInfo[] = 'Unknown Browser';
        }

        // Detect OS
        if (preg_match('/windows|win32|win64/i', $userAgent)) {
            $deviceInfo[] = 'Windows';
        } elseif (preg_match('/macintosh|mac os x/i', $userAgent)) {
            $deviceInfo[] = 'Mac OS';
        } elseif (preg_match('/android/i', $userAgent)) {
            $deviceInfo[] = 'Android';
        } elseif (preg_match('/iphone|ipad|ipod/i', $userAgent)) {
            $deviceInfo[] = 'iOS';
        } elseif (preg_match('/linux/i', $userAgent)) {
            $deviceInfo[] = 'Linux';
        } else {
            $deviceInfo[] = 'Unknown OS';
        }

        // Detect mobile
        if (preg_match('/mobile|android|iphone|ipad|ipod/i', $userAgent)) {
            $deviceInfo[] = 'Mobile';
        } else {
            $deviceInfo[] = 'Desktop';
        }

        return implode(', ', $deviceInfo);
    }
}
