<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    /**
     * Create a new order
     */
    public function store(StoreOrderRequest $request)
    {
        $order = Order::create([
            'user_id' => $request->user()->id,
            'total' => $request->total,
            'status' => 'pending',
        ]);

        // attach order items
        foreach ($request->items as $item) {
            $order->items()->create($item);
        }

        return new OrderResource($order);
    }

    /**
     * Show a specific order
     */
    public function show($id)
    {
        $order = Order::with('items.product')->findOrFail($id);

        return new OrderResource($order);
    }

    /**
     * List orders for authenticated user with search & pagination
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $q = $request->query('q', null);
        $perPage = (int) $request->query('per_page', 10);

        $query = $user->orders()->with('items.product')->orderBy('created_at', 'desc');

        if ($q) {
            $query->where(function ($sub) use ($q) {
                $sub->where('status', 'like', "%$q%")
                    ->orWhere('id', $q);
            });
        }

        $orders = $query->paginate($perPage);

        return response()->json([
            'data' => OrderResource::collection($orders)->resolve(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }
}
