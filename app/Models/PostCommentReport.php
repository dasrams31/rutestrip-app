<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A hiker's report on a Linimasa comment (one per hiker and comment). */
class PostCommentReport extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['post_comment_id', 'user_id', 'reason'];
}
