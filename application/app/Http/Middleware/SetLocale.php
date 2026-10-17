<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     * Web: checks unencrypted 'locale' cookie; defaults to 'ar' on first visit.
     * API: checks 'Accept-Language' header ('ar' or 'en'); falls back to 'ar'.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            $locale = null;

            if ($request->hasHeader('Accept-Language')) {
                $preferred = $request->getPreferredLanguage(['ar', 'en']);
                if (in_array($preferred, ['ar', 'en'], true)) {
                    $locale = $preferred;
                }
            }

            App::setLocale($locale ?: 'ar');
        } else {
            $locale = $request->cookie('locale');

            if (!is_string($locale) || !in_array($locale, ['ar', 'en'], true)) {
                $locale = 'ar';
            }

            App::setLocale($locale);
        }

        return $next($request);
    }
}
