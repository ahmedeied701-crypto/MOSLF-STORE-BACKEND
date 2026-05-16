<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
    ProductController,
    CollectionsController,
    CartController,
    AuthController,
    OrdersController
};
use App\Http\Controllers\UserDashboardController;
use App\Http\Resources\UserResource;

/*
|--------------------------------------------------------------------------
| Public Routes (Read-only resources)
|--------------------------------------------------------------------------
| These endpoints are safe for public access.
| No sensitive data should be exposed here.
*/

Route::prefix('products')->group(function () {
    // List all products (paginated, filtered)
    Route::get('/', [ProductController::class, 'index']);

    // Show single product using route model binding (slug)
    Route::get('/{product:slug}', [ProductController::class, 'show']);
});

Route::prefix('collections')->group(function () {
    // List all collections
    Route::get('/', [CollectionsController::class, 'index']);

    // Use route model binding instead of raw slug (prevents bugs)
    Route::get('/{collection:slug}', [CollectionsController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Cart Routes (Guest + Auth support)
|--------------------------------------------------------------------------
| IMPORTANT:
| - Do NOT expose cartId in URL (prevents IDOR attacks)
| - Cart should be resolved internally via:
|     - authenticated user OR
|     - secure guest token (cookie/session)
*/

Route::prefix('cart')->group(function () {
    // Get current cart (resolved internally)
    Route::get('/', [CartController::class, 'get']);

    // Add or update item in cart
    Route::post('/', [CartController::class, 'upsert']);

    // Update quantity of a specific variant
    Route::patch('/items/{variantId}', [CartController::class, 'setQuantity']);

    // Remove item from cart
    Route::delete('/items/{variantId}', [CartController::class, 'remove']);
});

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
| Apply rate limiting to prevent brute force attacks
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:3,1'); // max 5 requests per minute

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:3,1');

    Route::post('/2fa/verify', [AuthController::class, 'verify2FA'])
        ->middleware('throttle:3,1');
        
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('/logout', function (Request $request) {

            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Logged out successfully'
            ]);
        });
    });
});

// Route::middleware('auth:sanctum')->get('/test', function (Request $request) {
//     return $request->user();
// });
/*
|--------------------------------------------------------------------------
| Protected Routes (Requires Authentication)
|--------------------------------------------------------------------------
| All routes here require a valid Sanctum token
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authenticated User Info
    |--------------------------------------------------------------------------
    */

    Route::get('/auth/me', function (Request $request) {
        // for Eager loading
        $user = $request->user()->load(['cart.items.productVariant.product', 'cart.items.productVariant.images']);
        return response()->json([
            'user' => new UserResource($user)
        ]);
    });

    /*
    |--------------------------------------------------------------------------
    | User Dashboard & Profile
    |--------------------------------------------------------------------------
    */

    Route::prefix('user')->group(function () {

        // Dashboard overview (stats, summaries)
        Route::get('/dashboard', [UserDashboardController::class, 'index']);

        /*
        |--------------------------------------------------------------------------
        | Wishlist Management
        |--------------------------------------------------------------------------
        | NOTE:
        | - productId must be validated in controller
        | - ensure product exists before attaching
        */

        Route::get('/wishlist', [UserDashboardController::class, 'wishlist']);

        Route::post('/wishlist/{productId}', [UserDashboardController::class, 'addToWishlist'])
            ->whereNumber('productId');

        Route::delete('/wishlist/{productId}', [UserDashboardController::class, 'removeFromWishlist'])
            ->whereNumber('productId');

        /*
        |--------------------------------------------------------------------------
        | Orders
        |--------------------------------------------------------------------------
        | SECURITY:
        | - NEVER return orders without scoping to authenticated user
        | - Use policies or user_id filtering in controller
        */

        // List user orders (paginated)
        Route::get('/orders', [OrdersController::class, 'index']);

        // Get single order (must belong to user)
        Route::get('/orders/{order}', [OrdersController::class, 'show'])
            ->whereNumber('order');

        // Create new order from cart
        Route::post('/orders', [OrdersController::class, 'store']);

        /*
        |--------------------------------------------------------------------------
        | Cart Merge (Guest → Auth)
        |--------------------------------------------------------------------------
        | Merge guest cart into authenticated user cart
        */

        Route::post('/cart/merge', [CartController::class, 'merge']);
    });
});
