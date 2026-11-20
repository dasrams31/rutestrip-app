<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HikingRoute extends Model
{
    use HasFactory;

    /**
     * SEO-friendly URLs: /routes/21-gunung-sumbing-via-butuh-kaliangkrik.
     * Only the leading id is used to resolve, so old /routes/21 links keep working.
     */
    public function getRouteKey(): mixed
    {
        $slug = Str::slug((string) $this->name);

        return $slug !== '' ? $this->getKey() . '-' . $slug : (string) $this->getKey();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        return preg_match('/^(\d+)/', (string) $value, $m) ? $this->whereKey((int) $m[1])->first() : null;
    }

    protected $fillable = [
        'name',
        'description',
        'basecamp_name',
        'basecamp_address',
        'basecamp_lat',
        'basecamp_lng',
        'entry_fee',
        'contact_phone',
        'facilities',
        'best_season',
        'tips',
        'image_path',
        'uploaded_by',
        'route_coordinates',
        'gpx_file_path',
        'distance_km',
        'elevation_gain_m',
        'naismith_duration_hour',
        'average_grade_pct',
        'narrative_text',
        'embedding_text',
        'sbert_embedding',
        'sbert_aspect_embeddings',
    ];

    protected $casts = [
        'distance_km'            => 'float',
        'elevation_gain_m'       => 'integer',
        'naismith_duration_hour' => 'float',
        'average_grade_pct'      => 'float',
        'sbert_embedding'        => 'array',
        'sbert_aspect_embeddings' => 'array',
        'route_coordinates'      => 'array',
    ];

    protected static function booted(): void
    {
        // Keep search embeddings (name + narrative + aspects) in sync whenever the
        // text that feeds them changes. Disabled in tests via ROUTE_SEARCH_AUTO_INDEX=false.
        // (wasRecentlyCreated stays true on the same instance, so "created" and
        // "updated" are handled separately.)
        $index = function (HikingRoute $route) {
            if (config('services.python.auto_index', true)) {
                app(\App\Services\RouteSearchIndexer::class)->index($route);
            }
        };

        static::created($index);
        static::updated(function (HikingRoute $route) use ($index) {
            if ($route->wasChanged(['name', 'narrative_text', 'description'])) {
                $index($route);
            }
        });
    }

    /**
     * Get formatted distance
     */
    public function getFormattedDistanceAttribute(): string
    {
        return number_format($this->distance_km, 2) . ' km';
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDurationAttribute(): string
    {
        $hours   = floor($this->naismith_duration_hour);
        $minutes = round(($this->naismith_duration_hour - $hours) * 60);
        return __(':hours jam :minutes menit', ['hours' => $hours, 'minutes' => $minutes]);
    }

    /**
     * Get formatted grade
     */
    public function getFormattedGradeAttribute(): string
    {
        return number_format($this->average_grade_pct, 1) . '%';
    }

    /**
     * Highest point of the route in metres, taken from the narrative
     * ("... ketinggian dari 1800m hingga 3142m").
     */
    public function getMaxElevationMAttribute(): ?int
    {
        return preg_match('/hingga (\d+)m/', (string) $this->narrative_text, $m) ? (int) $m[1] : null;
    }

    /**
     * Difficulty score from the whole route profile (mirrors
     * python/processor.py difficulty_score()):
     * Shenandoah hiking difficulty rating sqrt(gain_ft × 2 × distance_mi),
     * ×1.15 for an average grade >= 25%, ×1.15 for a summit >= 3,000 m.
     */
    public function getDifficultyScoreAttribute(): float
    {
        $gainFt = max((float) $this->elevation_gain_m, 0) * 3.28084;
        $distMi = max((float) $this->distance_km, 0) * 0.621371;
        $score  = sqrt($gainFt * 2 * $distMi);

        if ((float) $this->average_grade_pct >= 25) {
            $score *= 1.15;
        }
        if (($this->max_elevation_m ?? 0) >= 3000) {
            $score *= 1.15;
        }

        return $score;
    }

    /**
     * Mudah < 75 <= Sedang < 200 <= Sulit < 460 <= Sangat Sulit.
     * A climb of 1,200 m or more is at least "Sulit" whatever the distance.
     */
    public function getDifficultyLevelAttribute(): string
    {
        $score = $this->difficulty_score;

        if ($score >= 460) {
            return 'Sangat Sulit';
        }
        if ($score >= 200 || (float) $this->elevation_gain_m >= 1200) {
            return 'Sulit';
        }
        if ($score >= 75) {
            return 'Sedang';
        }

        return 'Mudah';
    }

    /**
     * One-line explanation of the difficulty level (Indonesian; views pass it through __()).
     */
    public function getDifficultyHintAttribute(): string
    {
        return match ($this->difficulty_level) {
            'Mudah'  => 'Jalur pendek dan landai, cocok untuk pemula.',
            'Sedang' => 'Jarak dan tanjakan menengah, butuh stamina yang cukup.',
            'Sulit'  => 'Tanjakan panjang dan curam, untuk pendaki berpengalaman.',
            default  => 'Jalur panjang dan berat, butuh fisik prima dan persiapan matang.',
        };
    }

    /**
     * Relationships
     */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Name shown on the "Diunggah oleh" tag; routes imported by the team have no uploader.
     */
    public function getUploaderNameAttribute(): string
    {
        return $this->uploader?->name ?: 'Tim RuteStrip';
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    public function likes()
    {
        return $this->hasMany(Favorite::class);
    }

    public function gpxVersions()
    {
        return $this->hasMany(GpxVersion::class);
    }

    public function activeGpxVersion()
    {
        return $this->hasOne(GpxVersion::class)->where('is_active', true);
    }

    /**
     * Get average rating
     */
    public function getAverageRatingAttribute(): float
    {
        return round($this->ratings()->avg('rating') ?? 0, 1);
    }

    /**
     * Get total likes count
     */
    public function getLikesCountAttribute(): int
    {
        // withCount('likes') fills the attribute; otherwise count all likes (users + visitors).
        return (int) ($this->attributes['likes_count'] ?? $this->likes()->count());
    }

    /**
     * Check if user liked this route
     */
    public function isLikedBy($userId): bool
    {
        return $userId !== null && $this->likes()->where('user_id', $userId)->exists();
    }

    /**
     * Liked by the logged-in user, or (for guests) by the anonymous visitor id.
     */
    public function isLikedByViewer(?int $userId, ?string $visitorId): bool
    {
        if ($userId !== null) {
            return $this->isLikedBy($userId);
        }

        return $visitorId !== null
            && $this->likes()->whereNull('user_id')->where('visitor_id', $visitorId)->exists();
    }
}
