<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One message in the "Obrolan Pendaki" community chat (plain text). */
class CommunityMessage extends Model
{
    use SoftDeletes;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'room', 'body'];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who flagged the message (admin /admin/moderasi). */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
