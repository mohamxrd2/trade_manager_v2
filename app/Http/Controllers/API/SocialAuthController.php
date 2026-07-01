<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Redirect to provider
     */
    public function redirectToProvider(string $provider)
    {
        try {
            $validProviders = ['google', 'facebook'];
            
            if (!in_array($provider, $validProviders)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Provider non supporté'
                ], 400);
            }

            // Pour les API, on construit l'URL de redirection manuellement
            $config = config("services.{$provider}");
            $state = Str::random(40);
            
            $redirectUrl = "https://accounts.google.com/o/oauth2/auth?" . http_build_query([
                'client_id' => $config['client_id'],
                'redirect_uri' => $config['redirect'],
                'scope' => 'openid profile email',
                'response_type' => 'code',
                'state' => $state
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Redirection vers ' . ucfirst($provider),
                'redirect_url' => $redirectUrl
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la redirection',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle provider callback
     */
    public function handleProviderCallback(string $provider): JsonResponse
    {
        try {
            $validProviders = ['google', 'facebook'];
            
            if (!in_array($provider, $validProviders)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Provider non supporté'
                ], 400);
            }

            $socialUser = Socialite::driver($provider)->user();

            // Vérifier si l'utilisateur existe déjà
            $existingUser = User::where('email', $socialUser->getEmail())->first();

            if ($existingUser) {
                // L'utilisateur existe, on le connecte
                $user = $existingUser;
                
                // Mettre à jour les informations du provider si nécessaire
                if (!$user->provider_id) {
                    $user->update([
                        'provider' => $provider,
                        'provider_id' => $socialUser->getId()
                    ]);
                }
            } else {
                // Créer un nouvel utilisateur
                $user = $this->createUserFromSocialData($socialUser, $provider);
            }

            // Générer un token Sanctum
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Connexion réussie via ' . ucfirst($provider),
                'data' => [
                    'user' => $user,
                    'token' => $token,
                    'token_type' => 'Bearer'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion sociale',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create user from social data
     */
    private function createUserFromSocialData($socialUser, string $provider): User
    {
        $name = $socialUser->getName();
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0] ?? 'User';
        $lastName = $nameParts[1] ?? 'Social';

        // Générer un username unique
        $baseUsername = Str::slug($firstName . '_' . $lastName);
        $username = $baseUsername;
        $counter = 1;
        
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . '_' . $counter;
            $counter++;
        }

        // Générer un mot de passe aléatoire
        $randomPassword = Str::random(16);

        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username,
            'email' => $socialUser->getEmail(),
            'password' => Hash::make($randomPassword),
            'company_share' => 100.00,
            'profile_image' => $socialUser->getAvatar(),
            'provider' => $provider,
            'provider_id' => $socialUser->getId(),
        ]);
    }
}
