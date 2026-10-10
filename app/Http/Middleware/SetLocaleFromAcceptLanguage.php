<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uses the first supported language in Accept-Language (app.supported_locales) for messages
 * and emails. Without a supported language the application default (APP_LOCALE) is kept.
 */
class SetLocaleFromAcceptLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('app.supported_locales');

        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(strtok($language, '_-'));

            if (in_array($primary, $supported, true)) {
                app()->setLocale($primary);
                break;
            }
        }

        return $next($request);
    }
}
