<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/product-image/{filename}', function ($filename) {
    $path = storage_path('app/public/products/' . $filename);

    if (!file_exists($path)) {
        abort(404);
    }

    return response()->file($path)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Content-Type', mime_content_type($path));
});

Route::get('/send-test', function () {
    $inventory = \App\Models\Inventory::find(3);
    event(new \App\Events\InventoryUpdated($inventory));
    return "Event dispatched to Reverb!";
});
