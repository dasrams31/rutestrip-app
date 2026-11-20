<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    // A like belongs to a user, or to an anonymous visitor (rs_vid cookie) when user_id is null.
    protected $fillable = ['user_id', 'visitor_id', 'hiking_route_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function hikingRoute()
    {
        return $this->belongsTo(HikingRoute::class);
    }
}
