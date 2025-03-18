<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\BrandController as AdminBrandController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\InventoryController as AdminInventoryController;
use App\Http\Controllers\Api\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Api\Admin\ContentController as AdminContentController;
use App\Http\Controllers\Api\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::group(['prefix' => 'v1'], function () {
    // Authentication Routes
    Route::post('auth/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/login-with-otp', [AuthController::class, 'loginWithOtp']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

    // Category Routes
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'show']);
    Route::get('categories/{slug}/products', [CategoryController::class, 'products']);

    // Brand Routes
    Route::get('brands', [BrandController::class, 'index']);
    Route::get('brands/{slug}', [BrandController::class, 'show']);

    // Product Routes
    Route::get('products/featured', [ProductController::class, 'getFeatured']);
    Route::get('products/popular', [ProductController::class, 'getPopular']);
    Route::get('products/discounted', [ProductController::class, 'getDiscounted']);
    Route::get('products/new-arrivals', [ProductController::class, 'getNewArrivals']);
    Route::get('products/{slug}', [ProductController::class, 'show']);
    Route::get('products/{productId}/reviews', [ProductController::class, 'getReviews']);
    Route::post('products/search', [ProductController::class, 'search']);

    // Promotion Routes
    Route::get('promotions', [PromotionController::class, 'index']);
    Route::get('promotions/{id}', [PromotionController::class, 'show']);
    Route::get('promotions/type/{type}', [PromotionController::class, 'getByType']);
    Route::get('combo-offers', [PromotionController::class, 'comboOffers']);
    Route::get('combo-offers/{slug}', [PromotionController::class, 'comboOfferDetails']);

    // Collection Routes
    Route::get('collections', [CollectionController::class, 'index']);
    Route::get('collections/{slug}', [CollectionController::class, 'show']);

    // Content Routes
    Route::get('banners', [ContentController::class, 'getBanners']);
    Route::get('pages/{slug}', [ContentController::class, 'getPage']);
    Route::get('faqs', [ContentController::class, 'getFaqs']);
    Route::get('footer-pages', [ContentController::class, 'getFooterPages']);
    Route::get('header-pages', [ContentController::class, 'getHeaderPages']);
    Route::get('site-settings', [ContentController::class, 'getSiteSettings']);

    // Delivery Routes
    Route::get('delivery-areas', [DeliveryController::class, 'getDeliveryAreas']);
    Route::get('delivery-areas/{id}', [DeliveryController::class, 'getDeliveryAreaDetails']);
    Route::get('delivery-slots', [DeliveryController::class, 'getDeliverySlots']);
    Route::get('delivery-dates', [DeliveryController::class, 'getDeliveryDates']);
    Route::get('shipping-methods', [DeliveryController::class, 'getShippingMethods']);
    Route::post('calculate-shipping', [DeliveryController::class, 'calculateShipping']);

    // Cart Routes (with session-based identification)
    Route::get('cart', [CartController::class, 'getCart']);
    Route::post('cart/add', [CartController::class, 'addToCart']);
    Route::put('cart/items/{cartItemId}', [CartController::class, 'updateCartItem']);
    Route::delete('cart/items/{cartItemId}', [CartController::class, 'removeCartItem']);
    Route::post('cart/apply-coupon', [CartController::class, 'applyCoupon']);
    Route::post('cart/remove-coupon', [CartController::class, 'removeCoupon']);
    Route::get('cart/shipping-costs', [CartController::class, 'getShippingCosts']);
    Route::post('cart/update-shipping', [CartController::class, 'updateShipping']);
    Route::post('cart/add-notes', [CartController::class, 'addNotes']);
    Route::post('cart/clear', [CartController::class, 'clearCart']);

    // Order Routes (public access for guest checkout)
    Route::post('orders', [OrderController::class, 'placeOrder']);
    Route::get('orders/track', [OrderController::class, 'trackOrder']);
    Route::get('orders/{orderNumber}', [OrderController::class, 'getOrder']);
    Route::post('orders/{orderNumber}/cancel', [OrderController::class, 'cancelOrder']);
    Route::post('orders/{orderNumber}/verify-payment', [OrderController::class, 'verifyPayment']);

    // User Contact/Support Routes
    Route::post('contact', [UserController::class, 'submitContactMessage']);
    Route::post('subscribe', [UserController::class, 'subscribeNewsletter']);
    Route::post('unsubscribe', [UserController::class, 'unsubscribeNewsletter']);
});

// Protected routes (require authentication)
Route::group(['prefix' => 'v1', 'middleware' => ['auth:sanctum']], function () {
    // User Profile Routes
    Route::get('user', [AuthController::class, 'getUser']);
    Route::post('user/update-profile', [AuthController::class, 'updateProfile']);
    Route::post('user/change-password', [AuthController::class, 'changePassword']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // User Address Routes
    Route::get('user/addresses', [UserController::class, 'getAddresses']);
    Route::post('user/addresses', [UserController::class, 'addAddress']);
    Route::put('user/addresses/{id}', [UserController::class, 'updateAddress']);
    Route::delete('user/addresses/{id}', [UserController::class, 'deleteAddress']);
    Route::post('user/addresses/{id}/set-default', [UserController::class, 'setDefaultAddress']);

    // User Orders Routes
    Route::get('user/orders', [UserController::class, 'getOrderHistory']);

    // User Wishlist Routes
    Route::get('user/wishlist', [UserController::class, 'getWishlist']);
    Route::post('user/wishlist', [UserController::class, 'addToWishlist']);
    Route::delete('user/wishlist/{productId}', [UserController::class, 'removeFromWishlist']);

    // Reviews
    Route::post('products/{productId}/reviews', [ProductController::class, 'submitReview']);

    // Device Tokens for Push Notifications
    Route::post('user/device-tokens', [UserController::class, 'registerDeviceToken']);
    Route::delete('user/device-tokens', [UserController::class, 'unregisterDeviceToken']);
});

// Admin Auth Routes
Route::group(['prefix' => 'v1/admin'], function () {
    Route::post('login', [AdminAuthController::class, 'login']);
});

// Admin Routes - with admin authentication
Route::group(['prefix' => 'v1/admin', 'middleware' => ['auth:sanctum', 'admin']], function () {
    // Admin Auth Routes
    Route::get('user', [AdminAuthController::class, 'getUser']);
    Route::post('logout', [AdminAuthController::class, 'logout']);
    Route::post('change-password', [AdminAuthController::class, 'changePassword']);
    Route::post('update-profile', [AdminAuthController::class, 'updateProfile']);

    // Dashboard Routes
    Route::get('dashboard/stats', [AdminDashboardController::class, 'getStats']);
    Route::get('dashboard/sales-chart', [AdminDashboardController::class, 'getSalesChart']);

    // Orders Management
    Route::get('orders', [AdminOrderController::class, 'index']);
    Route::get('orders/{id}', [AdminOrderController::class, 'show']);
    Route::post('orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
    Route::post('orders/{id}/payment-status', [AdminOrderController::class, 'updatePaymentStatus']);
    Route::post('orders/{id}/assign-delivery', [AdminOrderController::class, 'assignDeliveryPerson']);
    Route::post('orders/{id}/notes', [AdminOrderController::class, 'addNote']);
    Route::get('orders-stats', [AdminOrderController::class, 'getStats']);

    // For existing controllers below, implement these when needed

    // Products Management
    Route::resource('products', AdminProductController::class);
    Route::post('products/{id}/status', [AdminProductController::class, 'updateStatus']);
    Route::post('products/import', [AdminProductController::class, 'import']);
    Route::get('products/export', [AdminProductController::class, 'export']);
    Route::post('products/{id}/images', [AdminProductController::class, 'uploadImages']);
    Route::delete('products/{id}/images/{imageId}', [AdminProductController::class, 'deleteImage']);
    Route::get('products/dropdowns-data', [AdminProductController::class, 'getDropdownsData']);



    // Categories Management
    Route::get('categories/parents-dropdown', [AdminCategoryController::class, 'getParentsForDropdown']);
    Route::resource('categories', AdminCategoryController::class);
    Route::post('categories/{id}/status', [AdminCategoryController::class, 'updateStatus']);

    // Brands Management
    Route::resource('brands', AdminBrandController::class);
    Route::post('brands/{id}/status', [AdminBrandController::class, 'updateStatus']);

    // Users Management
    Route::resource('users', AdminUserController::class);
    Route::post('users/{id}/status', [AdminUserController::class, 'updateStatus']);
    Route::get('customers', [AdminUserController::class, 'getCustomers']);
    Route::get('delivery-persons', [AdminUserController::class, 'getDeliveryPersons']);
    Route::get('admins', [AdminUserController::class, 'getAdmins']);

    // Inventory Management
    Route::get('inventory', [AdminInventoryController::class, 'index']);
    Route::post('inventory/{productId}/stock', [AdminInventoryController::class, 'updateStock']);
    Route::get('inventory/low-stock', [AdminInventoryController::class, 'getLowStock']);
    Route::get('inventory/transactions', [AdminInventoryController::class, 'getTransactions']);

    // Promotions Management
    // Route::resource('promotions', AdminPromotionController::class);
    // Route::post('promotions/{id}/status', [AdminPromotionController::class, 'updateStatus']);
    // Route::resource('combo-offers', AdminPromotionController::class);
    // Route::post('combo-offers/{id}/status', [AdminPromotionController::class, 'updateComboStatus']);

    // Content Management
    // Route::resource('banners', AdminContentController::class);
    // Route::resource('pages', AdminContentController::class);
    // Route::resource('faqs', AdminContentController::class);

    // Settings Management
    Route::get('settings', [AdminSettingController::class, 'index']);
    Route::post('settings', [AdminSettingController::class, 'update']);
    Route::get('settings/{group}', [AdminSettingController::class, 'getByGroup']);

    // Reports
    // Route::get('reports/sales', [AdminReportController::class, 'salesReport']);
    // Route::get('reports/products', [AdminReportController::class, 'productReport']);
    // Route::get('reports/customers', [AdminReportController::class, 'customerReport']);
});

// Add custom Admin middleware to RouteServiceProvider or create a new middleware
// to check for admin role

// public function handle(Request $request, Closure $next)
// {
//     if (!$request->user() || !in_array($request->user()->role, ['admin', 'manager'])) {
//         return response()->json([
//             'success' => false,
//             'message' => 'Unauthorized. Admin access required.'
//         ], 403);
//     }

//     return $next($request);
// }

