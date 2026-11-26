<?php

namespace App\Http\Controllers;

use App\Services\SocialService;
use App\Services\SsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Account settings for RuteStrip and the web chat (docs/SSO.md): one form per
 * section (profile, password, language), all posted to POST /settings.
 */
class SettingsController extends Controller
{
    public function __construct(private SsoService $sso)
    {
    }

    public function show()
    {
        return view('settings.index', ['user' => Auth::user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        return match ($request->input('section')) {
            'password' => $this->updatePassword($request),
            'locale'   => $this->updateLocale($request),
            default    => $this->updateProfile($request),
        };
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'bio'   => ['nullable', 'string', 'max:' . (SocialService::BIO_MAX * 2)],
        ], ...AuthController::validationTexts());

        // Bio (Linimasa profile): one line of plain text, max 300 characters.
        if ($request->exists('bio')) {
            $validated['bio'] = app(SocialService::class)->line($validated['bio'] ?? null);
            if ($validated['bio'] !== null && mb_strlen($validated['bio']) > SocialService::BIO_MAX) {
                throw ValidationException::withMessages(['bio' => __('Bio maks. :max karakter.', ['max' => SocialService::BIO_MAX])]);
            }
        }

        $user->update($validated);
        $this->reissueCookie($user);

        return redirect()->route('settings')->with('success', __('account.settings.profile_saved'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ], ...AuthController::validationTexts());

        $user = Auth::user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('account.settings.current_password_wrong'),
            ]);
        }

        // A new sso_version invalidates every rs_sso token issued before: other
        // browsers and the chat lose their session; this browser gets a new token.
        $user->forceFill([
            'password'    => Hash::make($validated['password']),
            'sso_version' => (int) $user->sso_version + 1,
        ])->save();

        $request->session()->regenerate();
        $this->reissueCookie($user);

        return redirect()->route('settings')->with('success', __('account.settings.password_saved'));
    }

    public function updateLocale(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', Rule::in(SsoService::LOCALES)],
        ], ...AuthController::validationTexts());

        $user = Auth::user();
        $user->update(['locale' => $validated['locale']]);
        app()->setLocale($validated['locale']);

        Cookie::queue($this->sso->langCookie($validated['locale']));
        $this->reissueCookie($user);

        return redirect()->route('settings')->with('success', __('account.settings.language_saved'));
    }

    /** Fresh rs_sso for this browser (name, email, locale or sv changed). */
    private function reissueCookie($user): void
    {
        Cookie::queue($this->sso->cookie($user->fresh()));
    }
}
