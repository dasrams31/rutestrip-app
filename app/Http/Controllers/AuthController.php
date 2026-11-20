<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AltchaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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
            throw ValidationException::withMessages(['altcha' => AltchaService::ERROR_MESSAGE]);
        }

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role'     => 'user',
        ]);

        Auth::login($user);

        return redirect()->intended(route('user.dashboard'))
            ->with('success', 'Registrasi berhasil! Selamat datang, ' . $user->name);
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
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $user     = Auth::user();
            $redirect = $user->isAdmin() ? route('admin.dashboard') : route('user.dashboard');

            return redirect()->intended($redirect)
                ->with('success', 'Selamat datang, ' . $user->name . '!');
        }

        return back()
            ->withErrors(['email' => 'Email atau password salah.'])
            ->withInput(['email' => $credentials['email']]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('success', 'Anda telah logout.');
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
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $user = Auth::user();

            // Check if user is admin
            if (! $user->isAdmin()) {
                Auth::logout();
                return back()
                    ->withErrors(['email' => 'Akun ini bukan akun admin. Silakan login di halaman user.'])
                    ->withInput(['email' => $credentials['email']]);
            }

            $request->session()->regenerate();

            return redirect()->intended(route('admin.dashboard'))
                ->with('success', 'Selamat datang, Admin ' . $user->name . '!');
        }

        return back()
            ->withErrors(['email' => 'Email atau password salah.'])
            ->withInput(['email' => $credentials['email']]);
    }
}
