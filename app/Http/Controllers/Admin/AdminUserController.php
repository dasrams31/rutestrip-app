<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\SsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** /admin/pengguna: account numbers, a searchable list, one page per account, admin role switch. */
class AdminUserController extends Controller
{
    public const SORTS = ['baru', 'aktif', 'nama'];

    public function index(Request $request)
    {
        $data = $request->validate([
            'q'    => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
        ]);
        $q    = trim((string) ($data['q'] ?? ''));
        $sort = $data['sort'] ?? 'baru';

        $since = fn (int $days) => now()->subDays($days);
        $summary = [
            'total'     => User::count(),
            'admins'    => User::where('role', 'admin')->count(),
            'new_7'     => User::where('created_at', '>=', $since(7))->count(),
            'new_30'    => User::where('created_at', '>=', $since(30))->count(),
            'active_7'  => User::where('last_login_at', '>=', $since(7))->count(),
            'active_30' => User::where('last_login_at', '>=', $since(30))->count(),
            'tracked'   => User::whereNotNull('last_login_at')->count(),
        ];

        $users = User::query()
            ->withCount(['uploads', 'favorites as likes_count', 'ratings as reviews_count', 'communityMessages as messages_count'])
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . $q . '%';
                $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when($sort === 'aktif', fn ($query) => $query->orderByRaw('last_login_at is null')->orderByDesc('last_login_at'))
            ->when($sort === 'nama', fn ($query) => $query->orderBy('name'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', compact('users', 'summary', 'q', 'sort'));
    }

    public function show(User $user)
    {
        $user->loadCount(['uploads', 'favorites as likes_count', 'ratings as reviews_count', 'communityMessages as messages_count', 'searchHistories']);

        $logs = $user->activityLogs()->with('user:id,name')->latest('created_at')->latest('id')->paginate(30);

        return view('admin.users.show', [
            'user'       => $user,
            'logs'       => $logs,
            'adminCount' => User::where('role', 'admin')->count(),
        ]);
    }

    public function updateRole(Request $request, User $user, ActivityLogger $activity, SsoService $sso): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(['admin', 'user'])]]);
        $from = $user->role;
        $to   = $data['role'];

        if ($from === $to) {
            return back();
        }

        // Count and change in one transaction so two admins demoting each other
        // at the same moment cannot leave the site without an admin.
        $changed = DB::transaction(function () use ($user, $to) {
            if ($to === 'user' && User::where('role', 'admin')->lockForUpdate()->count() <= 1) {
                return false;
            }
            // Demotion also bumps sso_version: older rs_sso tokens (which carry the role) stop working.
            $user->forceFill(['role' => $to] + ($to === 'user' ? ['sso_version' => (int) $user->sso_version + 1] : []))->save();

            return true;
        });

        if (! $changed) {
            return back()->withErrors(['role' => __('Peran admin tidak bisa dicabut: :name satu-satunya admin. Jadikan orang lain admin dulu.', ['name' => $user->name])]);
        }

        $activity->log('admin.role_changed', null, $user, ['target' => $user->name, 'from' => $from, 'to' => $to]);

        if ($user->is($request->user()) && $to === 'user') {
            // Demoted yourself: keep this browser signed in as a normal user.
            Cookie::queue($sso->cookie($user->fresh()));

            return redirect()->route('user.dashboard')->with('success', __('Peran admin kamu sudah dicabut.'));
        }

        return back()->with('success', $to === 'admin'
            ? __(':name sekarang admin.', ['name' => $user->name])
            : __(':name bukan admin lagi.', ['name' => $user->name]));
    }
}
