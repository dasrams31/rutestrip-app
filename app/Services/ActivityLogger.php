<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes the admin activity log. Logging must never break what the user is
 * doing, so every write is wrapped: a failure is reported and swallowed.
 */
class ActivityLogger
{
    /**
     * @param  User|int|null|false  $user  actor; null = the logged-in user (if any), false = nobody
     */
    public function log(string $action, User|int|null|false $user = null, ?Model $subject = null, array $meta = []): void
    {
        try {
            $userId = match (true) {
                $user instanceof User => $user->getKey(),
                is_int($user)         => $user,
                $user === false       => null,
                default               => Auth::id(),
            };

            $request = app()->bound('request') ? request() : null; // console: no REMOTE_ADDR, so ip stays null

            ActivityLog::create([
                'user_id'      => $userId,
                'action'       => $action,
                'subject_type' => $subject ? self::subjectType($subject) : null,
                'subject_id'   => $subject?->getKey(),
                'ip'           => self::anonymizeIp($request?->ip()),
                'user_agent'   => ($request ? mb_substr((string) $request->userAgent(), 0, 255) : '') ?: null,
                'meta'         => $meta ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Sign-in bookkeeping: one direct UPDATE (no model events, updated_at untouched). */
    public function recordLogin(User $user, string $via): void
    {
        try {
            DB::table('users')->where('id', $user->getKey())->update([
                'last_login_at' => now(),
                'login_count'   => DB::raw('login_count + 1'),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->log('auth.login', $user, null, ['via' => $via]);
    }

    /** IPv4: last octet zeroed (203.0.113.0). IPv6: first 48 bits kept (2001:db8:1::). */
    public static function anonymizeIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);

            return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));
        }

        return null;
    }

    private static function subjectType(Model $subject): string
    {
        return match ($subject::class) {
            \App\Models\HikingRoute::class      => 'route',
            \App\Models\GpxVersion::class       => 'gpx_version',
            \App\Models\CommunityMessage::class => 'community_message',
            \App\Models\User::class             => 'user',
            \App\Models\Rating::class           => 'rating',
            \App\Models\Comment::class          => 'comment',
            default                             => class_basename($subject),
        };
    }
}
