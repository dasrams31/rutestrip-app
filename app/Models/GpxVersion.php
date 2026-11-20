<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpxVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'hiking_route_id',
        'version_number',
        'gpx_file_path',
        'file_hash',
        'verification_status',
        'change_log',
        'uploaded_by',
        'is_active',
        'verified_at',
    ];

    protected $casts = [
        'is_active'              => 'boolean',
        'verified_at'            => 'datetime',
    ];

    public function route()
    {
        return $this->belongsTo(HikingRoute::class, 'hiking_route_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verificationLogs()
    {
        return $this->hasMany(VerificationLog::class);
    }

    public function getShortHashAttribute(): string
    {
        return substr($this->file_hash, 0, 12);
    }

    public function getVerificationStatusExplanationAttribute(): string
    {
        return match ($this->verification_status) {
            'verified' => 'File GPX cocok dengan hash tersimpan dan belum terdeteksi berubah.',
            'invalid' => 'Isi file GPX berbeda dari hash tersimpan sehingga perlu ditinjau.',
            'verification_failed' => 'Sistem belum berhasil memeriksa file GPX karena kendala teknis.',
            default => 'Status pemeriksaan file GPX belum tersedia.',
        };
    }
}
