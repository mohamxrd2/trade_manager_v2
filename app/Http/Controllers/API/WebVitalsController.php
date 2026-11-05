<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Reçoit les Core Web Vitals envoyés par navigator.sendBeacon() depuis le
 * front. Le corps arrive en text/plain (sendBeacon en JSON déclencherait un
 * preflight CORS) : on le décode à la main. Route publique, limitée en
 * débit, exclue du CSRF (bootstrap/app.php) — elle n'écrit que des mesures.
 */
class WebVitalsController extends Controller
{
    private const METRICS = ['LCP', 'INP', 'CLS', 'FCP', 'TTFB'];

    public function store(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);

        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'name' => ['required', 'string', 'in:' . implode(',', self::METRICS)],
            'value' => ['required', 'numeric', 'min:0', 'max:600000'],
            'rating' => ['nullable', 'string', 'in:good,needs-improvement,poor'],
            'page' => ['required', 'string', 'max:191'],
            'device' => ['nullable', 'string', 'in:mobile,desktop'],
        ]);

        if ($validator->fails()) {
            return response()->noContent(422);
        }

        $data = $validator->validated();

        DB::table('web_vitals')->insert([
            'name' => $data['name'],
            'value' => $data['value'],
            'rating' => $data['rating'] ?? null,
            // Les identifiants (UUID, nombres) sont remplacés par « :id » pour
            // regrouper /invoices/<uuid> sous une même page.
            'page' => preg_replace('#/([0-9a-f]{8}-[0-9a-f-]{27}|\d+)(?=/|$)#i', '/:id', $data['page']),
            'device' => $data['device'] ?? null,
            'created_at' => now(),
        ]);

        return response()->noContent();
    }
}
