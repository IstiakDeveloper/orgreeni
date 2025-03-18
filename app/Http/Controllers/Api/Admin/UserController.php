<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AdminActivity;
use App\Models\DeviceToken;
use App\Models\Address;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * Get all users with pagination and filtering
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $role = $request->input('role');
        $search = $request->input('search');
        $status = $request->input('status');

        $usersQuery = User::query();

        // Apply role filter
        if ($role) {
            $usersQuery->where('role', $role);
        }

        // Apply status filter
        if ($status !== null) {
            $usersQuery->where('is_active', $status === 'active');
        }

        // Apply search filter
        if ($search) {
            $usersQuery->where(function($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Order by recent users first
        $users = $usersQuery->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'users' => $users
            ]
        ]);
    }

    /**
     * Get customers with pagination and filtering
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCustomers(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');
        $status = $request->input('status');
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $customersQuery = User::where('role', 'customer');

        // Apply status filter
        if ($status !== null) {
            $customersQuery->where('is_active', $status === 'active');
        }

        // Apply search filter
        if ($search) {
            $customersQuery->where(function($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Apply sorting
        switch ($sortBy) {
            case 'name':
                $customersQuery->orderBy('name', $sortOrder);
                break;
            case 'phone':
                $customersQuery->orderBy('phone', $sortOrder);
                break;
            case 'orders_count':
                $customersQuery->withCount('orders')
                    ->orderBy('orders_count', $sortOrder);
                break;
            case 'total_spent':
                $customersQuery->withSum(['orders' => function($query) {
                        $query->where('status', 'delivered');
                    }], 'total')
                    ->orderBy('orders_sum_total', $sortOrder ?: 'desc');
                break;
            default:
                $customersQuery->orderBy($sortBy, $sortOrder);
        }

        // Load with order counts and other stats
        $customers = $customersQuery
            ->withCount('orders')
            ->withCount(['orders as completed_orders_count' => function($query) {
                $query->where('status', 'delivered');
            }])
            ->withSum(['orders' => function($query) {
                $query->where('status', 'delivered');
            }], 'total')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'customers' => $customers
            ]
        ]);
    }

    /**
     * Get delivery persons with pagination and filtering
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDeliveryPersons(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');
        $status = $request->input('status');

        $deliveryPersonsQuery = User::where('role', 'delivery');

        // Apply status filter
        if ($status !== null) {
            $deliveryPersonsQuery->where('is_active', $status === 'active');
        }

        // Apply search filter
        if ($search) {
            $deliveryPersonsQuery->where(function($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Order by name
        $deliveryPersons = $deliveryPersonsQuery->orderBy('name')
            ->withCount('deliveryOrders')
            ->withCount(['deliveryOrders as completed_orders_count' => function($query) {
                $query->where('status', 'delivered');
            }])
            ->withCount(['deliveryOrders as pending_orders_count' => function($query) {
                $query->whereIn('status', ['confirmed', 'processing', 'picked', 'shipped']);
            }])
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'delivery_persons' => $deliveryPersons
            ]
        ]);
    }

    /**
     * Get admin users with pagination and filtering
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAdmins(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');
        $status = $request->input('status');

        $adminsQuery = User::whereIn('role', ['admin', 'manager']);

        // Apply status filter
        if ($status !== null) {
            $adminsQuery->where('is_active', $status === 'active');
        }

        // Apply search filter
        if ($search) {
            $adminsQuery->where(function($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Order by role and name
        $admins = $adminsQuery->orderBy('role')
            ->orderBy('name')
            ->withCount('adminActivities')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'admins' => $admins
            ]
        ]);
    }

    /**
     * Get a specific user's details
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $user = User::findOrFail($id);

        // Load related data based on user role
        switch ($user->role) {
            case 'customer':
                $user->load(['addresses', 'orders' => function($query) {
                    $query->latest()->limit(10);
                }]);
                $user->orders_count = $user->orders()->count();
                $user->total_spent = $user->orders()->where('status', 'delivered')->sum('total');
                $user->addresses_count = $user->addresses()->count();
                $user->wishlists_count = $user->wishlists()->count();
                $user->first_order_date = $user->orders()->oldest()->value('created_at');
                $user->last_order_date = $user->orders()->latest()->value('created_at');
                break;

            case 'delivery':
                $user->load(['deliveryOrders' => function($query) {
                    $query->latest()->limit(10);
                }]);
                $user->delivery_orders_count = $user->deliveryOrders()->count();
                $user->completed_orders_count = $user->deliveryOrders()->where('status', 'delivered')->count();
                $user->on_going_orders_count = $user->deliveryOrders()
                    ->whereIn('status', ['confirmed', 'processing', 'picked', 'shipped'])
                    ->count();
                $user->first_delivery_date = $user->deliveryOrders()->oldest()->value('created_at');
                $user->last_delivery_date = $user->deliveryOrders()->latest()->value('created_at');
                break;

            case 'admin':
            case 'manager':
                $user->load(['adminActivities' => function($query) {
                    $query->latest()->limit(10);
                }]);
                $user->activities_count = $user->adminActivities()->count();
                $user->first_activity_date = $user->adminActivities()->oldest()->value('created_at');
                $user->last_activity_date = $user->adminActivities()->latest()->value('created_at');
                break;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user
            ]
        ]);
    }

    /**
     * Create a new user
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|size:11|unique:users',
            'email' => 'nullable|email|unique:users',
            'password' => 'required|string|min:6',
            'role' => 'required|in:customer,admin,manager,delivery',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Start transaction
        DB::beginTransaction();

        try {
            // Create new user
            $user = new User();
            $user->name = $request->name;
            $user->phone = $request->phone;
            $user->email = $request->email;
            $user->password = Hash::make($request->password);
            $user->role = $request->role;
            $user->is_active = $request->is_active ?? true;
            $user->phone_verified_at = now(); // Auto verify for admin-created users

            // Handle profile photo upload
            if ($request->hasFile('profile_photo')) {
                $profilePhoto = $request->file('profile_photo');
                $filename = time() . '_' . $user->phone . '.' . $profilePhoto->getClientOriginalExtension();
                $profilePhoto->storeAs('public/profile_photos', $filename);
                $user->profile_photo = $filename;
            }

            $user->save();

            // Add default address for delivery persons if provided
            if ($user->role === 'delivery' && $request->has('address_line')) {
                $addressValidator = Validator::make($request->all(), [
                    'address_line' => 'required|string|max:255',
                    'area' => 'required|string|max:100',
                    'city' => 'required|string|max:100',
                ]);

                if (!$addressValidator->fails()) {
                    $user->addresses()->create([
                        'address_line' => $request->address_line,
                        'area' => $request->area,
                        'city' => $request->city,
                        'postal_code' => $request->postal_code,
                        'landmark' => $request->landmark,
                        'is_default' => true
                    ]);
                }
            }

            // Log admin activity
            if ($request->user()) {
                AdminActivity::create([
                    'user_id' => $request->user()->id,
                    'activity_type' => 'user_created',
                    'subject_type' => 'user',
                    'subject_id' => $user->id,
                    'properties' => [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'user_role' => $user->role
                    ]
                ]);
            }

            // Commit transaction
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => [
                    'user' => $user
                ]
            ], 201);

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a user
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|size:11|unique:users,phone,' . $id,
            'email' => 'nullable|email|unique:users,email,' . $id,
            'role' => 'required|in:customer,admin,manager,delivery',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'is_active' => 'nullable|boolean',
            'password' => 'nullable|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Start transaction
        DB::beginTransaction();

        try {
            // Update user details
            $user->name = $request->name;
            $user->phone = $request->phone;
            $user->email = $request->email;
            $user->role = $request->role;
            $user->is_active = $request->is_active ?? $user->is_active;

            // Update password if provided
            if ($request->password) {
                $user->password = Hash::make($request->password);
            }

            // Handle profile photo upload
            if ($request->hasFile('profile_photo')) {
                // Delete old photo if exists
                if ($user->profile_photo) {
                    Storage::delete('public/profile_photos/' . $user->profile_photo);
                }

                $profilePhoto = $request->file('profile_photo');
                $filename = time() . '_' . $user->phone . '.' . $profilePhoto->getClientOriginalExtension();
                $profilePhoto->storeAs('public/profile_photos', $filename);
                $user->profile_photo = $filename;
            }

            $user->save();

            // Log admin activity
            if ($request->user()) {
                AdminActivity::create([
                    'user_id' => $request->user()->id,
                    'activity_type' => 'user_updated',
                    'subject_type' => 'user',
                    'subject_id' => $user->id,
                    'properties' => [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'user_role' => $user->role
                    ]
                ]);
            }

            // Commit transaction
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => [
                    'user' => $user
                ]
            ]);

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a user
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Prevent deleting self
        if ($request->user() && $request->user()->id == $id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account'
            ], 422);
        }

        // Check if user has orders
        if ($user->role === 'customer' && $user->orders()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete user with orders. Consider deactivating the account instead.'
            ], 422);
        }

        // Start transaction
        DB::beginTransaction();

        try {
            // Store user details for logging
            $userData = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_role' => $user->role,
                'user_phone' => $user->phone,
            ];

            // Delete related data
            $user->addresses()->delete();
            $user->deviceTokens()->delete();
            $user->wishlists()->delete();

            // Delete profile photo if exists
            if ($user->profile_photo) {
                Storage::delete('public/profile_photos/' . $user->profile_photo);
            }

            // Delete user
            $user->delete();

            // Log admin activity
            if ($request->user()) {
                AdminActivity::create([
                    'user_id' => $request->user()->id,
                    'activity_type' => 'user_deleted',
                    'properties' => $userData
                ]);
            }

            // Commit transaction
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user status (activate/deactivate)
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::findOrFail($id);

        // Prevent deactivating self
        if ($request->user() && $request->user()->id == $id && !$request->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot deactivate your own account'
            ], 422);
        }

        $user->is_active = $request->is_active;
        $user->save();

        // Log admin activity
        if ($request->user()) {
            AdminActivity::create([
                'user_id' => $request->user()->id,
                'activity_type' => $request->is_active ? 'user_activated' : 'user_deactivated',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'properties' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_role' => $user->role
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'User status updated successfully',
            'data' => [
                'user' => $user
            ]
        ]);
    }

    /**
     * Get user order history
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserOrders(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $perPage = $request->input('per_page', 15);
        $status = $request->input('status');

        $ordersQuery = $user->orders()->with(['items', 'deliveryArea', 'deliverySlot']);

        if ($status) {
            $ordersQuery->where('status', $status);
        }

        $orders = $ordersQuery->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone
                ],
                'orders' => $orders
            ]
        ]);
    }

    /**
     * Get user addresses
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserAddresses($id)
    {
        $user = User::findOrFail($id);
        $addresses = $user->addresses()->orderBy('is_default', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone
                ],
                'addresses' => $addresses
            ]
        ]);
    }

    /**
     * Get user statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserStats()
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $startOfYear = $now->copy()->startOfYear();

        $stats = [
            'total_users' => User::count(),
            'total_customers' => User::where('role', 'customer')->count(),
            'total_admins' => User::where('role', 'admin')->count(),
            'total_managers' => User::where('role', 'manager')->count(),
            'total_delivery_persons' => User::where('role', 'delivery')->count(),
            'active_users' => User::where('is_active', true)->count(),
            'inactive_users' => User::where('is_active', false)->count(),
            'new_users_today' => User::whereDate('created_at', today())->count(),
            'new_users_this_week' => User::whereBetween('created_at', [now()->startOfWeek(), now()])->count(),
            'new_users_this_month' => User::whereBetween('created_at', [$startOfMonth, $now])->count(),
            'new_users_this_year' => User::whereBetween('created_at', [$startOfYear, $now])->count(),
            'verified_users' => User::whereNotNull('phone_verified_at')->count(),
            'unverified_users' => User::whereNull('phone_verified_at')->count(),
        ];

        // User registration over time
        $usersByMonth = User::select(
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                DB::raw('COUNT(*) as count')
            )
            ->where('created_at', '>=', now()->subYear())
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->pluck('count', 'month')
            ->toArray();

        // User roles distribution
        $usersByRole = User::select('role', DB::raw('COUNT(*) as count'))
            ->groupBy('role')
            ->get()
            ->pluck('count', 'role')
            ->toArray();

        // Active/inactive distribution
        $usersByStatus = [
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count()
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'users_by_month' => $usersByMonth,
                'users_by_role' => $usersByRole,
                'users_by_status' => $usersByStatus
            ]
        ]);
    }

    /**
     * Get users who have their birthdays today or this month
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBirthdays(Request $request)
    {
        $type = $request->input('type', 'today');

        $now = now();
        $customersQuery = User::where('role', 'customer')->whereNotNull('date_of_birth');

        if ($type === 'today') {
            $customersQuery->whereMonth('date_of_birth', $now->month)
                ->whereDay('date_of_birth', $now->day);
        } else if ($type === 'month') {
            $customersQuery->whereMonth('date_of_birth', $now->month);
        }

        $customers = $customersQuery->get();

        return response()->json([
            'success' => true,
            'data' => [
                'customers' => $customers,
                'count' => $customers->count(),
                'type' => $type
            ]
        ]);
    }

    /**
     * Export users as CSV
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportUsers(Request $request)
    {
        $role = $request->input('role');
        $status = $request->input('status');

        $usersQuery = User::query();

        // Apply role filter
        if ($role) {
            $usersQuery->where('role', $role);
        }

        // Apply status filter
        if ($status !== null) {
            $usersQuery->where('is_active', $status === 'active');
        }

        $users = $usersQuery->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="users.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0'
        ];

        $callback = function() use ($users) {
            $file = fopen('php://output', 'w');

            // Add headers
            fputcsv($file, ['ID', 'Name', 'Phone', 'Email', 'Role', 'Status', 'Created At']);

            // Add data
            foreach ($users as $user) {
                fputcsv($file, [
                    $user->id,
                    $user->name,
                    $user->phone,
                    $user->email,
                    $user->role,
                    $user->is_active ? 'Active' : 'Inactive',
                    $user->created_at->format('Y-m-d H:i:s')
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
