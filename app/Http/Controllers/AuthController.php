<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AltchaService;
use App\Services\SsoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Sign-up rules, shared with the SSO API (POST /api/sso/register). */
    public const REGISTER_RULES = [
        'name'     => 'required|string|max:255',
        'email'    => 'required|string|email|max:255|unique:users,email',
        'password' => 'required|string|min:8|confirmed',
    ];

    public function __construct(private SsoService $sso)
    {
    }

    /** Translated validation messages and attribute names for the account forms. */
    public static function validationTexts(): array
    {
        return [__('account.validation'), __('account.attributes')];
    }

    /**
     * Show login form
     */
    public function showLoginForm()
    {
        if (Auth::check()) {
            return Auth::user()->isAdmin()
                ? redirect()->route('admin.dashboard')
                : redirect()->route('user.dashboard');
        }

        return view('auth.login');
    }

    /**
     * Show register form
     */
    public function showRegisterForm()
    {
        if (Auth::check()) {
            return redirect()->route('user.dashboard');
        }

        return view('auth.register');
    }

    /**
     * Handle registration
     */
    public function register(Request $request, AltchaService $altcha)
    {
        // Anti-robot checks run first so bots cannot probe which emails exist.
        // "website" is a honeypot field that humans never see.
        if (filled($request->input('website')) || ! $altcha->verify($request->input('altcha'))) {
            throw ValidationException::withMessages(['altcha' => __('account.altcha_failed')]);
        }

        $validated = $request->validate(self::REGISTER_RULES, ...self::validationTexts());

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role'     => 'user',
            'locale'   => $this->sso->normalizeLocale(app()->getLocale()),
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        Cookie::queue($this->sso->cookie($user));

        return redirect()->intended(route('user.dashboard'))
            ->with('success', __('account.register.success', ['name' => $user->name]));
    }

    /**
     * Issue a fresh ALTCHA proof-of-work challenge for the register form
     */
    public function altchaChallenge(AltchaService $altcha)
    {
        return response()->json($altcha->createChallenge())
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Handle login attempt
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ], ...self::validationTexts());

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $user = Auth::user();
            Cookie::queue($this->sso->cookie($user));
            $redirect = $user->isAdmin() ? route('admin.dashboard') : route('user.dashboard');

            return redirect()->intended($redirect)
                ->with('success', __('account.login.welcome', ['name' => $user->name]));
        }

        return back()
            ->withErrors(['email' => __('account.login.failed')])
            ->withInput(['email' => $credentials['email']]);
    }

    /**
     * Logout user (here and, through the shared rs_sso cookie, on the chat)
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue($this->sso->forgetCookie());

        return redirect()->route('login')
            ->with('success', __('account.logout.success'));
    }

    /**
     * Show admin login form (separate from user login)
     */
    public function showAdminLoginForm()
    {
        if (Auth::check()) {
            return Auth::user()->isAdmin()
                ? redirect()->route('admin.dashboard')
                : redirect()->route('user.dashboard');
        }

        return view('auth.admin-login');
    }

    /**
     * Handle admin login attempt (only admin role allowed)
     */
    public function adminLogin(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ], ...self::validationTexts());

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $user = Auth::user();

            // Check if user is admin
            if (! $user->isAdmin()) {
                Auth::logout();
                return back()
                    ->withErrors(['email' => __('account.admin.not_admin')])
                    ->withInput(['email' => $credentials['email']]);
            }

            $request->session()->regenerate();
            Cookie::queue($this->sso->cookie($user));

            return redirect()->intended(route('admin.dashboard'))
                ->with('success', __('account.admin.welcome', ['name' => $user->name]));
        }

        return back()
            ->withErrors(['email' => __('account.login.failed')])
            ->withInput(['email' => $credentials['email']]);
    }
}
