<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
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

        // Connexion automatique après l'inscription
        Auth::guard('web')->login($user);

        // Régénération de la session pour la sécurité
        $request->session()->regenerate();

        // Retourner directement l'utilisateur (format simplifié, comme login)
        return response()->json($user, 201);
    }

    /**
     * Login user
     */
    public function login(Request $request): JsonResponse
    {
        // Validation : accepter soit 'email', soit 'username', soit 'login'
        $request->validate([
            'login' => 'sometimes|string',
            'email' => 'sometimes|email',
            'username' => 'sometimes|string',
            'password' => 'required|string',
        ]);

        $password = $request->input('password');
        $remember = $request->boolean('remember', false);

        // Déterminer le champ de connexion (email ou username)
        // Priorité: login > email > username
        $loginField = $request->input('login') ?? $request->input('email') ?? $request->input('username');
        
        // Vérifier qu'au moins un champ de connexion est fourni
        if (!$loginField) {
            return response()->json(['message' => 'Email, username ou login requis'], 422);
        }
        
        // Vérifier si c'est un email ou un username
        $isEmail = filter_var($loginField, FILTER_VALIDATE_EMAIL);
        
        // Rechercher l'utilisateur par email ou username
        $user = null;
        if ($isEmail) {
            $user = User::where('email', $loginField)->first();
        } else {
            $user = User::where('username', $loginField)->first();
        }

        // Vérifier si l'utilisateur existe et si le mot de passe est correct
        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json(['message' => 'Identifiants invalides'], 401);
        }

        // Connexion de l'utilisateur avec le guard 'web'
        Auth::guard('web')->login($user, $remember);

        // Régénération de la session pour la sécurité
        $request->session()->regenerate();

        // Retourner directement l'utilisateur (format simplifié)
        return response()->json($user);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        // Déconnexion avec le guard 'web'
        Auth::guard('web')->logout();

        // Invalidation de la session
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Déconnexion réussie']);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Charger les relations nécessaires
        $user->load(['company', 'settings']);

        // Retourner directement l'utilisateur (format simplifié)
        return response()->json($user);
    }
}
