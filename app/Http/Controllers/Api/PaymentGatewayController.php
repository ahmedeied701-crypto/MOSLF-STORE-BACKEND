<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentGatewayResource;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;

class PaymentGatewayController extends Controller
{
    /**
     * List all active payment gateways
     */
    public function index()
    {
        $gateways = PaymentGateway::active()->get(); 
        return PaymentGatewayResource::collection($gateways);
    }

    /**
     * Show a specific payment gateway by ID
     */
    public function show($id)
    {
        $gateway = PaymentGateway::findOrFail($id);
        return new PaymentGatewayResource($gateway);
    }
}
