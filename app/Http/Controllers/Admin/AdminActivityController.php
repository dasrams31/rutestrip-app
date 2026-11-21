<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SearchStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** /admin/aktivitas: 30 days per day, most searched phrases, latest log entries. */
class AdminActivityController extends Controller
{
    public const DAYS = 30;

    public function index()
    {
        $today = CarbonImmutable::today();
        $start = $today->subDays(self::DAYS - 1);
        $days  = collect(range(0, self::DAYS - 1))->map(fn ($i) => $start->addDays($i)->toDateString());

        $perDay = fn (string $table, string $column = 'created_at', ?callable $scope = null) => DB::table($table)
            ->when($scope, $scope)
            ->where($column, '>=', $start)
            ->selectRaw("date({$column}) as d, count(*) as c")
            ->groupBy('d')
            ->pluck('c', 'd');

        $guestSearches = DB::table('search_stats')->where('day', '>=', $start->toDateString())
            ->selectRaw('day as d, sum(hits) as c')->groupBy('day')->pluck('c', 'd')
            ->mapWithKeys(fn ($c, $d) => [substr((string) $d, 0, 10) => $c]);
        $userSearches = $perDay('search_histories');

        $series = [
            ['key' => 'searches', 'label' => __('Pencarian'), 'note' => __('pengguna dan tamu'),
                'counts' => $days->map(fn ($d) => (int) ($userSearches[$d] ?? 0) + (int) ($guestSearches[$d] ?? 0))],
            ['key' => 'uploads', 'label' => __('File GPX masuk'), 'note' => __('jalur baru dan versi baru'), 'counts' => $this->fill($days, $perDay('gpx_versions'))],
            ['key' => 'likes', 'label' => __('Suka'), 'note' => __('pengguna dan tamu'), 'counts' => $this->fill($days, $perDay('favorites'))],
            ['key' => 'reviews', 'label' => __('Ulasan'), 'note' => __('rating baru'), 'counts' => $this->fill($days, $perDay('ratings'))],
            ['key' => 'messages', 'label' => __('Pesan obrolan'), 'note' => __('termasuk yang sudah dihapus'), 'counts' => $this->fill($days, $perDay('community_messages'))],
            ['key' => 'signups', 'label' => __('Akun baru'), 'note' => __('pendaftaran'), 'counts' => $this->fill($days, $perDay('users'))],
        ];
        foreach ($series as &$s) {
            $s['total'] = $s['counts']->sum();
            $s['max']   = $s['counts']->max();
        }
        unset($s);

        return view('admin.activity', [
            'days'     => $days,
            'series'   => $series,
            'top'      => $this->mostSearched($start),
            'feed'     => ActivityLog::with('user:id,name')->latest('created_at')->latest('id')->limit(50)->get(),
            'start'    => $start,
            'today'    => $today,
        ]);
    }

    private function fill($days, $counts)
    {
        return $days->map(fn ($d) => (int) ($counts[$d] ?? 0));
    }

    /**
     * Top phrases since $start: users' searches (search_histories, normalised here)
     * plus guests' daily counts (search_stats, normalised when stored).
     *
     * @return list<array{query: string, users: int, guests: int, total: int}>
     */
    private function mostSearched(CarbonImmutable $start, int $limit = 15): array
    {
        $rows = [];

        DB::table('search_histories')->where('created_at', '>=', $start)->select('query')
            ->orderBy('id')->lazy(1000)
            ->each(function ($r) use (&$rows) {
                $q = SearchStat::normalize((string) $r->query);
                if ($q !== '') {
                    $rows[$q]['users'] = ($rows[$q]['users'] ?? 0) + 1;
                }
            });

        DB::table('search_stats')->where('day', '>=', $start->toDateString())
            ->selectRaw('query, sum(hits) as hits')->groupBy('query')->get()
            ->each(function ($r) use (&$rows) {
                $rows[$r->query]['guests'] = ($rows[$r->query]['guests'] ?? 0) + (int) $r->hits;
            });

        $out = [];
        foreach ($rows as $q => $r) {
            $users  = $r['users'] ?? 0;
            $guests = $r['guests'] ?? 0;
            $out[]  = ['query' => (string) $q, 'users' => $users, 'guests' => $guests, 'total' => $users + $guests];
        }
        usort($out, fn ($a, $b) => [$b['total'], $a['query']] <=> [$a['total'], $b['query']]);

        return array_slice($out, 0, $limit);
    }
}
