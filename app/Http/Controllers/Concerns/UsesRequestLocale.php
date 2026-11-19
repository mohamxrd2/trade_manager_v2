<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/** Messages traduits (lang/{fr,en}) dans la langue envoyée par le front (Accept-Language). */
trait UsesRequestLocale
{
    protected function useRequestLocale(Request $request): void
    {
        app()->setLocale($request->getPreferredLanguage(['fr', 'en']) ?? 'fr');
    }
}
