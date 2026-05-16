<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Models\Role;
use App\Services\Cart\CartService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\AuthLoginRequest;
use App\Http\Requests\AuthRegisterRequest;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Support\Str;


class AuthController extends Controller
{

    /**
     * Register a new user
     */
    public function register(AuthRegisterRequest $request)
    {
        $password = Hash::make($request->password);
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $password,
        ]);

        $role = Role::firstOrCreate([
            'name' => 'user',
            'guard_name' => 'api',
        ]);

        $user->assignRole($role);

        /** @var \App\Models\User $user */
        $token = $user->createToken('api-token')->plainTextToken;

        app(CartService::class)->mergeGuestCart($request);

        $user->load(['cart.items.productVariant.product', 'cart.items.productVariant.images']);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    /**
     * Login user and return token
     */
    public function login(AuthLoginRequest $request)
    {
        /** @var \App\Models\User $user */

        $credentials = $request->only('email', 'password');

        if (!Auth::guard('web')->attempt($credentials)) {
            return response()->json([
                'message' => 'The email or password you entered is incorrect'
            ], 401);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();


        if ($user->hasRole('admin') && !empty($user->google2fa_secret)) {

            $payload = $user->id . '|' . time();
            $tempToken = Str::random(32);

            $signature = hash_hmac(
                'sha256',
                $payload . '|' . $tempToken,
                config('app.key')
            );

            $finalToken = base64_encode($payload . '|' . $tempToken . '|' . $signature);

            return response()->json([
                // 'requires_2fa' => true,
                'status' => '2fa_required',
                'temp_token' => $finalToken,
                'expires_in' => 30
            ], 200);
        }

        $user->tokens()->delete();

        $token = $user->createToken('api-token')->plainTextToken;


        // Merge guest cart into authenticated user cart
        app(CartService::class)->mergeGuestCart($request);

        $user->load(['cart.items.productVariant.product', 'cart.items.productVariant.images']);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 200);
    }

    public function setup2FA(Request $request)
    {
        $user = $request->user();
        $google2fa = new Google2FA();

        if (!$user->google2fa_secret) {
            $user->google2fa_secret = $google2fa->generateSecretKey();
            $user->save();
        }

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            'MOSLF',
            $user->email,
            $user->google2fa_secret
        );

        $renderer = new \BaconQrCode\Renderer\Image\SvgImageBackEnd();
        $writer = new \BaconQrCode\Writer(
            new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(250),
                $renderer
            )
        );

        $svgImage = $writer->writeString($qrCodeUrl);

        return response($svgImage)
            ->header('Content-Type', 'image/svg+xml')
            ->header('X-2FA-Secret', $user->google2fa_secret);
    }

    public function verify2FA(Request $request)
    {
        $request->validate([
            'one_time_password' => 'required',
            'temp_token' => 'required',
        ]);

        $decoded = base64_decode($request->temp_token);

        if (!$decoded) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $parts = explode('|', $decoded);

        if (count($parts) !== 4) {
            return response()->json(['message' => 'Invalid token structure'], 401);
        }

        [$userId, $timestamp, $random, $signature] = $parts;

        if (!is_numeric($timestamp)) {
            return response()->json(['message' => 'Invalid timestamp'], 401);
        }

        $expectedSignature = hash_hmac(
            'sha256',
            $userId . '|' . $timestamp . '|' . $random,
            config('app.key')
        );

        if (!hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'message' => 'Invalid authentication token'
            ], 401);
        }


        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'Invalid user'], 401);
        }

        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized flow'], 401);
        }


        $ts = (int) $timestamp;

        if ($ts > time() + 5) {
            return response()->json(['message' => 'Invalid timestamp'], 401);
        }

        if (time() - $ts > 30) {
            return response()->json(['message' => 'Token expired'], 401);
        }

        // $user = User::findOrFail($userId);

        $google2fa = new Google2FA();

        $valid = $google2fa->verifyKey(
            $user->google2fa_secret,
            $request->one_time_password,
            0
        );

        if (!$valid) {
            return response()->json([
                'message' => 'The authentication code is incorrect'
            ], 403);
        }

        $user->tokens()->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        app(CartService::class)->mergeGuestCart($request);

        $user->load(['cart.items.productVariant.product', 'cart.items.productVariant.images']);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 200);
    }
}
