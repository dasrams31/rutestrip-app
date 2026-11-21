<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\LaravelLogReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** /admin/log: filterable activity log, failed sign-ins of the last 24 h, end of laravel.log. */
class AdminLogController extends Controller
{
    /** Failures from one address or for one email in 24 h from which the row is flagged. */
    public const FAILED_FLAG = 5;

    public function index(Request $request, LaravelLogReader $reader)
    {
        $filters = $request->validate([
            'action' => ['nullable', Rule::in(array_keys(ActivityLog::actionLabels()))],
            'user'   => ['nullable', 'string', 'max:120'],
            'from'   => ['nullable', 'date_format:Y-m-d'],
            'to'     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $who = trim((string) ($filters['user'] ?? ''));
        $logs = ActivityLog::with('user:id,name,email')
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($who !== '', function ($q) use ($who) {
                ctype_digit($who)
                    ? $q->where('user_id', (int) $who)
                    : $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$who}%")->orWhere('email', 'like', "%{$who}%"));
            })
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->latest('created_at')->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.logs', [
            'logs'     => $logs,
            'filters'  => $filters + ['user' => $who],
            'failed'   => $this->failedLogins(),
            'server'   => $reader->tail(200),
            'filtered' => array_filter($filters) !== [],
        ]);
    }

    /** Failed sign-ins of the last 24 h grouped per (anonymised) address and per email tried. */
    private function failedLogins(): array
    {
        $rows = ActivityLog::where('action', 'auth.failed')
            ->where('created_at', '>=', now()->subDay())
            ->latest('created_at')->limit(5000)
            ->get(['ip', 'meta', 'created_at']);

        $group = fn (callable $key) => $rows->groupBy($key)
            ->reject(fn ($g, $k) => $k === '')
            ->map(fn ($g, $k) => ['key' => (string) $k, 'count' => $g->count(), 'last' => $g->max('created_at')])
            ->sortByDesc('count')->take(10)->values()->all();

        return [
            'total'  => $rows->count(),
            'byIp'   => $group(fn ($r) => (string) $r->ip),
            'byEmail' => $group(fn ($r) => (string) ($r->meta['email'] ?? '')),
        ];
    }
}
