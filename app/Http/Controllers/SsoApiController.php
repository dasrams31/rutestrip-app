<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AltchaService;
use App\Services\SsoService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Stateless JSON API the web chat uses to sign users in (docs/SSO.md).
 * Messages follow Accept-Language (id default, en supported).
 */
class SsoApiController extends Controller
{
    private const LOGIN_PER_MINUTE = 10;

    private const REGISTER_PER_MINUTE = 5;

    public function __construct(private SsoService $sso, private ActivityLogger $activity)
    {
    }

    /** POST /api/sso/login {email, password} */
    public function login(Request $request): JsonResponse
    {
        $this->useRequestLocale($request);

        if ($throttled = $this->throttle($request, 'login', self::LOGIN_PER_MINUTE)) {
            return $throttled;
        }

        $validator = Validator::make($request->all(), [
            'email'    => 'required|string|email|max:255',
            'password' => 'required|string|max:1024',
        ], ...AuthController::validationTexts());
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $user = User::where('email', $request->input('email'))->first();
        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            // Same entry as a failed web sign-in: the email tried, never the password.
            $this->activity->log('auth.failed', $user ?? false, null, [
                'email' => mb_strtolower(mb_substr(trim((string) $request->input('email')), 0, 255)),
                'via'   => 'chat',
            ]);

            return $this->error(__('account.api.invalid_credentials'), 401);
        }

        // No session guard here, so the Login event does not fire: record the sign-in directly.
        $this->activity->recordLogin($user, 'chat');

        return response()->json([
            'token' => $this->sso->issue($user),
            'user'  => $this->sso->userPayload($user),
        ]);
    }

    /** POST /api/sso/register {name, email, password, password_confirmation, altcha, website} */
    public function register(Request $request, AltchaService $altcha): JsonResponse
    {
        $locale = $this->useRequestLocale($request);

        if ($throttled = $this->throttle($request, 'register', self::REGISTER_PER_MINUTE)) {
            return $throttled;
        }

        // Same anti-robot rules as the /register form, checked before anything else.
        $payload = $request->input('altcha');
        if (filled($request->input('website')) || ! $altcha->verify(is_string($payload) ? $payload : null)) {
            return $this->validationError(['altcha' => [__('account.altcha_failed')]]);
        }

        $validator = Validator::make($request->all(), AuthController::REGISTER_RULES, ...AuthController::validationTexts());
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        $validated = $validator->validated();

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role'     => 'user',
            'locale'   => $locale,
        ]);
        $user->refresh();
        event(new Registered($user));
        $this->activity->recordLogin($user, 'chat');

        return response()->json([
            'token' => $this->sso->issue($user),
            'user'  => $this->sso->userPayload($user),
        ], 201);
    }

    /** GET /api/sso/me with Authorization: Bearer <token> */
    public function me(Request $request): JsonResponse
    {
        $this->useRequestLocale($request);

        $user = $this->sso->user($request->bearerToken());
        if (! $user) {
            return $this->error(__('account.api.invalid_token'), 401);
        }

        return response()->json(['user' => $this->sso->userPayload($user)]);
    }

    private function useRequestLocale(Request $request): string
    {
        $locale = $this->sso->requestLocale($request);
        app()->setLocale($locale);

        return $locale;
    }

    /** Fixed one-minute window per client IP (forwarded IP only with the internal secret). */
    private function throttle(Request $request, string $action, int $perMinute): ?JsonResponse
    {
        $key = 'sso-api:' . $action . ':' . $this->sso->clientIp($request);

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $seconds = RateLimiter::availableIn($key);

            return $this->error(__('account.api.throttled', ['seconds' => $seconds]), 429)
                ->header('Retry-After', (string) $seconds);
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    private function validationError(array $errors): JsonResponse
    {
        $first = collect($errors)->flatten()->first();

        return response()->json(['error' => $first, 'errors' => $errors], 422);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
