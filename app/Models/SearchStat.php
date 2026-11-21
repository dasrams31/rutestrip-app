<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Daily guest search counts per normalised phrase (no IP, no visitor id). */
class SearchStat extends Model
{
    public $timestamps = false;

    protected $fillable = ['day', 'query', 'hits'];

    public const MAX_LENGTH = 120;

    /** Lowercase, trimmed, inner whitespace collapsed, at most 120 characters. */
    public static function normalize(string $query): string
    {
        $q = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''));

        return trim(mb_substr($q, 0, self::MAX_LENGTH));
    }

    /** +1 for today's row of this phrase. Never throws: a failed count must not break the search. */
    public static function record(string $query): void
    {
        $q = self::normalize($query);
        if ($q === '') {
            return;
        }

        try {
            DB::table('search_stats')->upsert(
                [['day' => now()->toDateString(), 'query' => $q, 'hits' => 1]],
                ['day', 'query'],
                ['hits' => DB::raw('hits + 1')]
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
