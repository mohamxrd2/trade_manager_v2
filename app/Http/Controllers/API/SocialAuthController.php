<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Providers actuellement supportés.
     * Apple sera ajouté séparément (nécessite socialiteproviders/apple).
     */
    private const VALID_PROVIDERS = ['google', 'facebook'];

    /**
     * Redirect the user to the OAuth provider's consent screen.
     *
     * Doit être appelée par une navigation plein écran du navigateur
     * (window.location.href / <a href>), pas par un fetch/AJAX,
     * puisque la redirection suivante quitte le domaine de l'app.
     *
     * Stateless : la redirect_uri de certains providers (ex: Google) pointe
     * directement vers le frontend, donc notre backend ne reverra jamais la
     * requête retour de Google pour vérifier le "state" stocké en session.
     */
    public function redirectToProvider(string $provider): RedirectResponse
    {
        if (!in_array($provider, self::VALID_PROVIDERS)) {
            return redirect(rtrim(config('app.frontend_url'), '/') . '/login?error=invalid_provider');
        }

        $driver = Socialite::driver($provider)->stateless();

        // Force l'écran de sélection de compte Google, sinon un utilisateur
        // déjà connecté à Google dans son navigateur est reconnecté
        // automatiquement avec ce compte sans pouvoir en choisir un autre.
        if ($provider === 'google') {
            $driver = $driver->with(['prompt' => 'select_account']);
        }

        return $driver->redirect();
    }

    /**
     * Handle the OAuth provider callback (flow "backend reçoit tout").
     *
     * C'est le provider qui redirige le navigateur ici, en plein écran.
     * On connecte l'utilisateur via la session (cohérent avec AuthController::login)
     * puis on redirige vers le frontend : jamais de JSON brut à cette étape.
     *
     * Utilisé quand la redirect URI enregistrée chez le provider pointe vers
     * CE backend (ex: Facebook, actuellement).
     */
    public function handleProviderCallback(Request $request, string $provider): RedirectResponse
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        if (!in_array($provider, self::VALID_PROVIDERS)) {
            return redirect($frontendUrl . '/login?error=invalid_provider');
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();

            $this->loginSocialUser($request, $socialUser, $provider);

            return redirect($frontendUrl . '/auth/callback');
        } catch (\Exception $e) {
            Log::error('Erreur de connexion sociale', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return redirect($frontendUrl . '/login?error=social_auth_failed');
        }
    }

    /**
     * Exchange un code OAuth relayé par le frontend (flow "frontend reçoit le code").
     *
     * Utilisé quand la redirect URI enregistrée chez le provider pointe vers
     * le FRONTEND (ex: Google, actuellement) : le provider redirige le
     * navigateur vers le frontend avec ?code=..., le frontend nous relaie ce
     * code en POST, et nous seuls (backend) l'échangeons avec le provider
     * car cet échange nécessite le client secret.
     *
     * POST /api/auth/{provider}/exchange
     * Body: { "code": "..." }
     */
    public function exchangeCode(Request $request, string $provider): JsonResponse
    {
        if (!in_array($provider, self::VALID_PROVIDERS)) {
            return response()->json([
                'success' => false,
                'message' => 'Provider non supporté',
            ], 400);
        }

        // Un code OAuth est à usage unique. Si le frontend l'envoie deux fois
        // (double appel côté client), le 2e échange échouerait avec Google
        // ("invalid_grant") alors que la session est déjà valide depuis le 1er.
        // On court-circuite cette course dans ce cas précis.
        if (Auth::guard('web')->check()) {
            return response()->json([
                'success' => true,
                'message' => 'Déjà connecté',
                'data' => ['user' => Auth::guard('web')->user()],
            ]);
        }

        $request->validate([
            'code' => 'required|string',
        ]);

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();

            $user = $this->loginSocialUser($request, $socialUser, $provider);

            return response()->json([
                'success' => true,
                'message' => 'Connexion réussie via ' . ucfirst($provider),
                'data' => ['user' => $user],
            ]);
        } catch (\Throwable $e) {
            Log::error('Erreur d\'échange de code OAuth', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'La connexion a échoué',
            ], 422);
        }
    }

    /**
     * Trouve ou crée l'utilisateur à partir des données du provider,
     * puis le connecte via la session (même guard que AuthController::login).
     */
    private function loginSocialUser(Request $request, $socialUser, string $provider): User
    {
        if (!$socialUser->getEmail()) {
            throw new \RuntimeException('email_not_shared');
        }

        $user = User::where('email', $socialUser->getEmail())->first();

        if ($user) {
            // Lier le compte au provider s'il n'était pas déjà lié
            if (!$user->provider_id) {
                $user->update([
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                ]);
            }
        } else {
            $user = $this->createUserFromSocialData($socialUser, $provider);
        }

        // Connexion via la session (même guard que AuthController::login)
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $user;
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

        $user = User::create([
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

        // L'email est déjà vérifié par le provider (Google/Facebook).
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
