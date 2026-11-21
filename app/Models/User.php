<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'sso_version'       => 'integer',
            'last_login_at'     => 'datetime',
            'login_count'       => 'integer',
        ];
    }

    // Check if user is admin
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    // Relationships
    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function favoriteRoutes()
    {
        return $this->belongsToMany(HikingRoute::class, 'favorites');
    }

    public function searchHistories()
    {
        return $this->hasMany(SearchHistory::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    /** Routes this account uploaded (hiking_routes.uploaded_by). */
    public function uploads()
    {
        return $this->hasMany(HikingRoute::class, 'uploaded_by');
    }

    public function communityMessages()
    {
        return $this->hasMany(CommunityMessage::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}
