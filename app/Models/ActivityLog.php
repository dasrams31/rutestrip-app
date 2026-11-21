<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the admin activity log (written by App\Services\ActivityLogger).
 * meta keeps the names needed to describe the entry after the subject is gone
 * (route name, message author), never passwords or message bodies.
 */
class ActivityLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const KEEP_DAYS = 180;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'ip', 'user_agent', 'meta'];

    protected function casts(): array
    {
        return [
            'meta'       => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Short labels for the log filter, in the order they are offered. */
    public static function actionLabels(): array
    {
        return [
            'auth.login'                 => __('Masuk'),
            'auth.failed'                => __('Gagal masuk'),
            'auth.logout'                => __('Keluar'),
            'auth.registered'            => __('Daftar akun'),
            'auth.password_changed'      => __('Ganti password'),
            'auth.admin_denied'          => __('Ditolak di panel admin'),
            'route.created'              => __('Jalur diunggah'),
            'route.updated'              => __('Jalur diubah'),
            'route.deleted'              => __('Jalur dihapus'),
            'gpx_version.created'        => __('Versi GPX baru'),
            'gpx_version.restored'       => __('Versi GPX dipulihkan'),
            'review.created'             => __('Ulasan dikirim'),
            'review.deleted'             => __('Ulasan dihapus'),
            'community.message_reported' => __('Pesan dilaporkan'),
            'community.message_deleted'  => __('Pesan dihapus admin'),
            'community.report_dismissed' => __('Laporan diabaikan'),
            'admin.role_changed'         => __('Peran diubah'),
            'admin.bulk_action'          => __('Pemeriksaan massal'),
            'admin.evaluation_snapshot'  => __('Snapshot evaluasi'),
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    public function label(): string
    {
        return self::actionLabels()[$this->action] ?? $this->action;
    }

    /** One plain sentence for the feed, e.g. "Rama mengunggah Gunung Prau via Wates". */
    public function sentence(): string
    {
        $m    = $this->meta ?? [];
        $name = $this->user?->name ?? ($m['user_name'] ?? null);
        $who  = $name ?? ($this->user_id ? __('Akun yang sudah dihapus') : __('Server'));
        $what = $m['name'] ?? __('jalur #:id', ['id' => $this->subject_id]);
        $p    = ['name' => $who, 'subject' => $what];

        return match ($this->action) {
            'auth.login'                 => __(':name masuk ke akun', $p),
            'auth.failed'                => __('Percobaan masuk gagal untuk :email', ['email' => $m['email'] ?? '-']),
            'auth.logout'                => __(':name keluar', $p),
            'auth.registered'            => __(':name membuat akun', $p),
            'auth.password_changed'      => ($m['by'] ?? null) === 'server'
                ? __('Password :name diganti dari server', $p)
                : __(':name mengganti password', $p),
            'auth.admin_denied'          => __(':name mencoba masuk ke panel admin tanpa izin', $p),
            'route.created'              => __(':name mengunggah :subject', $p),
            'route.updated'              => __(':name mengubah data :subject', $p),
            'route.deleted'              => __(':name menghapus jalur :subject', $p),
            'gpx_version.created'        => __(':name menambah versi :version untuk :subject', $p + ['version' => $m['version'] ?? '?']),
            'gpx_version.restored'       => __(':name memulihkan versi :version :subject', $p + ['version' => $m['version'] ?? '?']),
            'review.created'             => __(':name memberi ulasan :rating bintang untuk :subject', $p + ['rating' => $m['rating'] ?? '?']),
            'review.deleted'             => __('Ulasan :author untuk :subject dihapus', $p + ['author' => $m['author'] ?? __('pendaki')]),
            'community.message_reported' => __(':name melaporkan pesan dari :author', $p + ['author' => $m['author'] ?? __('pendaki')]),
            'community.message_deleted'  => __(':name menghapus pesan dari :author', $p + ['author' => $m['author'] ?? __('pendaki')]),
            'community.report_dismissed' => __(':name mengabaikan laporan pesan dari :author', $p + ['author' => $m['author'] ?? __('pendaki')]),
            'admin.role_changed'         => ($m['to'] ?? null) === 'admin'
                ? __(':name menjadikan :target admin', $p + ['target' => $m['target'] ?? '-'])
                : __(':name mencabut peran admin :target', $p + ['target' => $m['target'] ?? '-']),
            'admin.bulk_action'          => __(':name menjalankan pemeriksaan ulang semua file GPX aktif', $p),
            'admin.evaluation_snapshot'  => __(':name menyimpan snapshot evaluasi', $p),
            default                      => $who . ': ' . $this->action,
        };
    }
}
