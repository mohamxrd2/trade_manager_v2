<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware pour vérifier que l'entreprise de l'utilisateur est complète
 * avant d'accéder aux fonctionnalités de facturation.
 * 
 * Vérifie que les champs obligatoires sont remplis :
 * - name (obligatoire dès l'onboarding)
 * - email (obligatoire pour facturation)
 * - logo (obligatoire pour facturation)
 */
class EnsureCompanyInvoiceReady
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'code' => 'UNAUTHORIZED',
                'message' => 'Utilisateur non authentifié',
            ], 401);
        }

        // Charger l'entreprise de l'utilisateur
        $company = $user->company;

        if (!$company) {
            Log::warning('Invoice access blocked: No company', [
                'user_id' => $user->id,
                'route' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'code' => 'COMPANY_NOT_FOUND',
                'message' => 'Aucune entreprise associée à votre compte. Veuillez compléter l\'onboarding.',
            ], 403);
        }

        // Vérifier les champs obligatoires pour la facturation
        $missingFields = [];

        if (empty($company->name)) {
            $missingFields[] = 'name';
        }

        if (empty($company->email)) {
            $missingFields[] = 'email';
        }

        if (empty($company->logo)) {
            $missingFields[] = 'logo';
        }

        if (!empty($missingFields)) {
            Log::warning('Invoice access blocked: Company incomplete', [
                'user_id' => $user->id,
                'company_id' => $company->id,
                'missing' => $missingFields,
                'route' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'code' => 'COMPANY_INCOMPLETE',
                'message' => 'Votre entreprise doit être complète pour accéder à la facturation.',
                'missing' => $missingFields,
                'missing_details' => [
                    'name' => empty($company->name),
                    'email' => empty($company->email),
                    'logo' => empty($company->logo),
                ],
            ], 403);
        }

        return $next($request);
    }
}

