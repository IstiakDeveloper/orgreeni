<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Admin login
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

        // Check if user exists with admin privilege
        $user = User::where('phone', $request->phone)
            ->whereIn('role', ['admin', 'manager'])
            ->first();

        // Check if user exists and password is correct
        if (!$user || !Hash::check($request->password, $user->password)) {
            // Log failed login attempt
            $this->logActivity(
                $user ? $user->id : null,
                'login_failed',
                $request->ip(),
                $request->userAgent(),
                ['message' => 'Invalid credentials']
            );

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        // Check if user is active
        if (!$user->is_active) {
            $this->logActivity(
                $user->id,
                'login_failed',
                $request->ip(),
                $request->userAgent(),
                ['message' => 'Account is inactive']
            );

            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact super admin.'
            ], 403);
        }

        // Create admin token with admin abilities
        $token = $user->createToken('admin_token', ['admin']);

        // Log successful login
        $this->logActivity(
            $user->id,
            'login_success',
            $request->ip(),
            $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user,
                'token' => $token->plainTextToken,
                'abilities' => ['admin']
            ]
        ]);
    }

    /**
     * Admin logout
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        // Log the logout activity
        $this->logActivity(
            $request->user()->id,
            'logout',
            $request->ip(),
            $request->userAgent()
        );

        // Revoke the token that was used to authenticate the current request
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Successfully logged out'
        ]);
    }

    /**
     * Get authenticated admin user
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
     * Change admin password
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

        // Log the password change
        $this->logActivity(
            $user->id,
            'password_changed',
            $request->ip(),
            $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    /**
     * Update admin profile
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

        // Log the profile update
        $this->logActivity(
            $user->id,
            'profile_updated',
            $request->ip(),
            $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'user' => $user
            ]
        ]);
    }

    /**
     * Log admin activity
     *
     * @param int|null $userId
     * @param string $activityType
     * @param string $ipAddress
     * @param string $userAgent
     * @param array $properties
     * @return void
     */
    private function logActivity($userId, $activityType, $ipAddress, $userAgent, $properties = null)
    {
        try {
            AdminActivity::create([
                'user_id' => $userId,
                'activity_type' => $activityType,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'properties' => $properties
            ]);
        } catch (\Exception $e) {
            // Just log the error, don't stop execution
            \Illuminate\Support\Facades\Log::error('Failed to log admin activity: ' . $e->getMessage());
        }
    }
}
