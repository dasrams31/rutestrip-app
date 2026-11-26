<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A hiker's report on a Linimasa post (one per hiker and post). */
class PostReport extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['post_id', 'user_id', 'reason'];
}
