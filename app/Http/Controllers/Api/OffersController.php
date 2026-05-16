<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use Illuminate\Http\Request;

class OffersController extends Controller
{
    /**
     * List all active offers
     */
    public function index()
    {
        $offers = Offer::active()->get();
        return OfferResource::collection($offers);
    }

    /**
     * Show a specific offer by ID or slug
     */
    public function show($id)
    {
        $offer = Offer::findOrFail($id);
        return new OfferResource($offer);
    }
}
