<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Controller;
use App\Services\SsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Signed-in account for the mobile app (docs/API.md): dashboard numbers, search
 * history and the settings the website has (profile, password, language).
 * Same rules as SettingsController; a password change invalidates every older
 * token (sso_version) and returns a new one for this device.
 */
class AccountApiController extends Controller
{
    public function __construct(private SsoService $sso)
    {
    }

    /** GET /api/v1/me: user, dashboard numbers and the five latest searches. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user'  => $this->sso->userPayload($user),
            'stats' => [
                'favorites' => $user->favorites()->count(),
                'searches'  => $user->searchHistories()->count(),
                'comments'  => $user->comments()->count(),
                'ratings'   => $user->ratings()->count(),
            ],
            'recent_searches' => $user->searchHistories()->latest('id')->take(5)->get()->map(fn($h) => $this->history($h))->values(),
        ]);
    }

    /** GET /api/v1/me/history?page=: search history, 20 per page. */
    public function historyIndex(Request $request): JsonResponse
    {
        $page = $request->user()->searchHistories()->latest('id')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn($h) => $this->history($h))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** PUT /api/v1/me/profile {name, email} */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validate($request, [
            'name'  => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $user->update($data);

        return response()->json(['user' => $this->sso->userPayload($user->fresh()), 'message' => __('account.settings.profile_saved')]);
    }

    /** PUT /api/v1/me/password {current_password, password, password_confirmation}: returns a new token. */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'current_password' => 'required|string|max:1024',
            'password'         => 'required|string|min:8|max:1024|confirmed',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            $message = __('account.settings.current_password_wrong');

            return response()->json(['error' => $message, 'errors' => ['current_password' => [$message]]], 422);
        }

        $user->forceFill(['password' => Hash::make($data['password']), 'sso_version' => (int) $user->sso_version + 1])->save();
        $user->refresh();

        return response()->json([
            'token'   => $this->sso->issue($user),
            'user'    => $this->sso->userPayload($user),
            'message' => __('account.settings.password_saved'),
        ]);
    }

    /** PUT /api/v1/me/locale {locale: id|en} */
    public function updateLocale(Request $request): JsonResponse
    {
        $data = $this->validate($request, ['locale' => ['required', Rule::in(SsoService::LOCALES)]]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $request->user()->update(['locale' => $data['locale']]);

        return response()->json(['user' => $this->sso->userPayload($request->user()->fresh())]);
    }

    /**
     * POST /api/v1/me/logout-all: "Keluar dari semua perangkat". Bumps sso_version, so every
     * token and website session of this account ends, as after a password change.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['sso_version' => (int) $user->sso_version + 1])->save();

        return response()->json(['ok' => true]);
    }

    private function history($h): array
    {
        return ['id' => (int) $h->id, 'query' => $h->query, 'results_count' => (int) $h->results_count, 'created_at' => $h->created_at?->toIso8601String()];
    }

    /** Validation with the website's messages, answered as {error, errors}. */
    private function validate(Request $request, array $rules): array|JsonResponse
    {
        $v = Validator::make($request->all(), $rules, ...AuthController::validationTexts());
        if ($v->fails()) {
            $errors = $v->errors()->toArray();

            return response()->json(['error' => collect($errors)->flatten()->first(), 'errors' => $errors], 422);
        }

        return $v->validated();
    }
}
