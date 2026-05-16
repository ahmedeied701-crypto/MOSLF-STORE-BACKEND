<?php

declare(strict_types=1);

use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\Admin\AdminProductController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;

/*
|--------------------------------------------------------------------------
| Admin API Routes — Product Management & Inventory Control
|--------------------------------------------------------------------------
|
| All routes are versioned under /api/v1/ and protected by Sanctum auth.
| To extend: add new resource groups here (invoices, orders, etc.)
|
*/

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'role:admin'])
    ->group(function () {


        Route::get('/admin', function (Request $request) {
            return response()->json([
                'user' => $request->user()->load('roles')
            ]);
        });

        /*
        |--------------------------------------------------------------------------
        | 2FA SECURITY (Admin Only)
        |--------------------------------------------------------------------------
        */
        // QR Code
        Route::post('/auth/2fa/setup', [AuthController::class, 'setup2FA']);

        /*
        |--------------------------------------------------------------------------
        | PRODUCTS (RBAC Protected)
        |--------------------------------------------------------------------------
        */

        // View products (list + single)
        Route::middleware('permission:products.view')->group(function () {
            Route::get('/products', [AdminProductController::class, 'index']);
            Route::get('/products/{product}', [AdminProductController::class, 'show'])->withTrashed();
        });

        // Create product
        Route::post('/products', [AdminProductController::class, 'store'])
            ->middleware('permission:products.create');

        // Update product
        Route::put('/products/{product}', [AdminProductController::class, 'update'])
            ->middleware('permission:products.update')
            ->withTrashed();

        // Update product variations
        Route::put(
            '/products/{product}/variations/{variation}',
            [AdminProductController::class, 'updateVariation']
        )->middleware('permission:products.update')
            ->withTrashed();

        // Delete product
        Route::delete('/products/{product}', [AdminProductController::class, 'destroy'])
            ->middleware('permission:products.delete');

        /*
        |--------------------------------------------------------------------------
        | INVENTORY (Nested RBAC)
        |--------------------------------------------------------------------------
        */

        Route::prefix('products/{product}/variations/{variation}/inventory')
            ->group(function () {

                // View inventory
                Route::get('/', [InventoryController::class, 'show'])
                    ->middleware('permission:inventory.view')
                    ->withTrashed();

                // View stock movements
                Route::get('/movements', [InventoryController::class, 'movements'])
                    ->middleware('permission:inventory.view_movements')
                    ->withTrashed();

                // Add stock movement (adjust stock)
                Route::post('/movements', [InventoryController::class, 'addMovement'])
                    ->middleware('permission:inventory.update')
                    ->withTrashed();
            });
    });
