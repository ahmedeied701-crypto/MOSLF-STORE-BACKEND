<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryProductsRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;

class CategoriesController extends Controller
{
    /**
     * List all categories (as tree)
     */
    public function index()
    {
        $categories = Category::with('children')->get();
        return CategoryResource::collection($categories);
    }

    /**
     * Show products under a category (including its children)
     */
    public function products(CategoryProductsRequest $request, string $slug)
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        // Get all child categories recursively
        $categoryIds = $this->getCategoryAndChildrenIds($category);

        $products = $category->products()
            ->whereHas('categories', fn($q) => $q->whereIn('id', $categoryIds))
            ->paginate($request->limit ?? 12);

        return response()->json([
            'category' => new CategoryResource($category),
            'products' => $products,
        ]);
    }

    /**
     * Helper: get category ID + all children IDs recursively
     */
    private function getCategoryAndChildrenIds(Category $category)
    {
        $ids = [$category->id];

        foreach ($category->children as $child) {
            $ids = array_merge($ids, $this->getCategoryAndChildrenIds($child));
        }

        return $ids;
    }
}
