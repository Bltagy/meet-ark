<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Store explicit locale preference in cookie and redirect back.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:ar,en'],
        ]);

        $locale = $validated['locale'];

        return back()->withCookie(
            cookie()->forever('locale', $locale, httpOnly: false, sameSite: 'lax')
        );
    }

    /**
     * Allow GET /locale/{locale} shortcut if accessed directly.
     */
    public function set(string $locale): RedirectResponse
    {
        if (!in_array($locale, ['ar', 'en'], true)) {
            $locale = 'ar';
        }

        return back()->withCookie(
            cookie()->forever('locale', $locale, httpOnly: false, sameSite: 'lax')
        );
    }
}
