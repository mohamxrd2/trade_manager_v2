<?php

use Illuminate\Support\Facades\Log;

it('journalise une requête API plus lente que le seuil', function () {
    config(['app.slow_request_ms' => 0]);
    Log::spy();

    test()->call('POST', '/api/web-vitals', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{}');

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Slow request'
        && $context['route'] === 'api/web-vitals'
        && isset($context['duration_ms'], $context['queries']))->once();
});

it('ne journalise pas une requête rapide', function () {
    config(['app.slow_request_ms' => 60000]);
    Log::spy();

    test()->call('POST', '/api/web-vitals', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{}');

    Log::shouldNotHaveReceived('warning', fn ($message) => $message === 'Slow request');
});
