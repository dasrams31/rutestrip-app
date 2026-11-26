<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A plain-text comment on a Linimasa post. */
class PostComment extends Model
{
    use SoftDeletes;

    protected $fillable = ['post_id', 'user_id', 'body'];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** One row per hiker who reported the comment (table post_comment_reports). */
    public function reports(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PostCommentReport::class);
    }
}
