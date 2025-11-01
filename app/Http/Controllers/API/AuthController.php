<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\LoginRequest;
use App\Http\Requests\API\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'username' => $request->username,
            'company_share' => $request->company_share ?? 100.00,
            'profile_image' => $request->profile_image,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur créé avec succès',
            'data' => [
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer'
            ]
        ], 201);
    }

    /**
     * Login user
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $loginField = $request->input('login'); // email or username
        $password = $request->input('password');
        $remember = $request->boolean('remember', false);

        // Determine if login field is email or username
        $isEmail = filter_var($loginField, FILTER_VALIDATE_EMAIL);
        
        if ($isEmail) {
            $credentials = [
                'email' => $loginField,
                'password' => $password
            ];
        } else {
            $credentials = [
                'username' => $loginField,
                'password' => $password
            ];
        }

        // Find user by email or username
        $user = null;
        if ($isEmail) {
            $user = \App\Models\User::where('email', $loginField)->first();
        } else {
            $user = \App\Models\User::where('username', $loginField)->first();
        }

        // Check if user exists and password is correct
        if ($user && \Illuminate\Support\Facades\Hash::check($password, $user->password)) {
            // Create token with longer expiration if remember me is checked
            $tokenName = $remember ? 'remember_token' : 'auth_token';
            $token = $user->createToken($tokenName)->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Connexion réussie',
                'data' => [
                    'user' => $user,
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'remember' => $remember
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Identifiants invalides'
        ], 401);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie'
        ]);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $request->user()
            ]
        ]);
    }
}
