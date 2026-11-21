<?php

namespace App\Support;

use App\Models\BulkProvenanceAction;
use App\Models\Comment;
use App\Models\CommunityMessage;
use App\Models\GpxVersion;
use App\Models\HikingRoute;
use App\Models\Rating;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Auth;

/**
 * Feeds the admin activity log from framework and model events, so the
 * controllers stay free of logging calls. Registered in AppServiceProvider
 * (kept out of app/Listeners so event discovery does not register it twice).
 *
 * Login covers every way in: the login and admin forms, registration,
 * the remember-me cookie and the SSO cookie (SsoBridge). The JSON API
 * (/api/sso/login) does not use the session guard and logs itself.
 */
class ActivitySubscriber
{
    /** Route columns a person edits; embeddings, stats and file paths change on their own. */
    private const ROUTE_FIELDS = ['name', 'description', 'basecamp_name', 'basecamp_address', 'basecamp_lat', 'basecamp_lng',
        'entry_fee', 'contact_phone', 'facilities', 'best_season', 'tips', 'image_path'];

    public function __construct(private ActivityLogger $log)
    {
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class      => 'login',
            Failed::class     => 'failed',
            Logout::class     => 'logout',
            Registered::class => 'registered',
            'eloquent.updated: ' . User::class               => 'userUpdated',
            'eloquent.created: ' . HikingRoute::class        => 'routeCreated',
            'eloquent.updated: ' . HikingRoute::class        => 'routeUpdated',
            'eloquent.deleted: ' . HikingRoute::class        => 'routeDeleted',
            'eloquent.created: ' . GpxVersion::class         => 'versionCreated',
            'eloquent.updated: ' . GpxVersion::class         => 'versionUpdated',
            'eloquent.updated: ' . CommunityMessage::class   => 'messageUpdated',
            'eloquent.deleted: ' . CommunityMessage::class   => 'messageDeleted',
            'eloquent.deleted: ' . Rating::class             => 'reviewDeleted',
            'eloquent.deleted: ' . Comment::class            => 'reviewDeleted',
            'eloquent.created: ' . BulkProvenanceAction::class => 'bulkAction',
        ];
    }

    private static function routeIs(string ...$names): bool
    {
        return (bool) request()->route()?->named(...$names);
    }

    public function login(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        // The admin form signs a non-admin in and straight out again: not a sign-in.
        if (self::routeIs('admin.login.submit') && ! $event->user->isAdmin()) {
            $this->log->log('auth.admin_denied', $event->user);

            return;
        }

        $via = match (true) {
            self::routeIs('login.submit')       => 'form',
            self::routeIs('admin.login.submit') => 'admin',
            self::routeIs('register.submit')    => 'register',
            $event->remember                    => 'remember',
            default                             => 'sso',
        };

        $this->log->recordLogin($event->user, $via);
    }

    public function failed(Failed $event): void
    {
        $email = mb_strtolower(mb_substr(trim((string) ($event->credentials['email'] ?? '')), 0, 255));

        $this->log->log('auth.failed', $event->user instanceof User ? $event->user : false, null, array_filter([
            'email' => $email,
            'via'   => self::routeIs('admin.login.submit') ? 'admin' : 'form',
        ]));
    }

    public function logout(Logout $event): void
    {
        if (! $event->user instanceof User || self::routeIs('admin.login.submit')) {
            return;
        }

        $this->log->log('auth.logout', $event->user, null, ['via' => self::routeIs('logout') ? 'button' : 'sso']);
    }

    public function registered(Registered $event): void
    {
        if ($event->user instanceof User) {
            $this->log->log('auth.registered', $event->user, null, ['via' => request()->is('api/*') ? 'chat' : 'web']);
        }
    }

    public function userUpdated(User $user): void
    {
        if ($user->wasChanged('password')) {
            $by = match (true) {
                (int) Auth::id() === (int) $user->id => 'self',
                Auth::check()            => 'admin',
                default                  => 'server',
            };
            $this->log->log('auth.password_changed', $user, $user, ['by' => $by]);
        }
    }

    public function routeCreated(HikingRoute $route): void
    {
        $this->log->log('route.created', $route->uploaded_by ? (int) $route->uploaded_by : null, $route, ['name' => $route->name]);
    }

    public function routeUpdated(HikingRoute $route): void
    {
        $changed = array_values(array_intersect(self::ROUTE_FIELDS, array_keys($route->getChanges())));
        if ($changed) {
            $this->log->log('route.updated', null, $route, ['name' => $route->name, 'fields' => $changed]);
        }
    }

    public function routeDeleted(HikingRoute $route): void
    {
        $this->log->log('route.deleted', null, $route, ['name' => $route->name]);
    }

    public function versionCreated(GpxVersion $version): void
    {
        // Version 1 arrives together with the route ("route.created" says it already).
        if ((int) $version->version_number > 1) {
            $this->log->log('gpx_version.created', $version->uploaded_by ? (int) $version->uploaded_by : null, $version, [
                'name' => $version->route?->name, 'version' => (int) $version->version_number, 'route_id' => (int) $version->hiking_route_id,
            ]);
        }
    }

    public function versionUpdated(GpxVersion $version): void
    {
        if ($version->wasChanged('is_active') && $version->is_active) {
            $this->log->log('gpx_version.restored', null, $version, [
                'name' => $version->route?->name, 'version' => (int) $version->version_number, 'route_id' => (int) $version->hiking_route_id,
            ]);
        }
    }

    public function messageUpdated(CommunityMessage $message): void
    {
        if (! $message->wasChanged('reported_at')) {
            return;
        }

        $meta = ['author' => $message->user?->name, 'author_id' => (int) $message->user_id];
        $message->reported_at !== null
            ? $this->log->log('community.message_reported', $message->reported_by ? (int) $message->reported_by : null, $message, $meta)
            : $this->log->log('community.report_dismissed', null, $message, $meta);
    }

    public function messageDeleted(CommunityMessage $message): void
    {
        // People deleting their own messages is not moderation.
        if (Auth::check() && (int) Auth::id() !== (int) $message->user_id) {
            $this->log->log('community.message_deleted', null, $message, [
                'author' => $message->user?->name, 'author_id' => (int) $message->user_id, 'was_reported' => $message->reported_at !== null,
            ]);
        }
    }

    public function reviewDeleted(Rating|Comment $review): void
    {
        $this->log->log('review.deleted', null, $review, [
            'name' => HikingRoute::whereKey($review->hiking_route_id)->value('name'), 'author' => User::whereKey($review->user_id)->value('name'),
        ]);
    }

    public function bulkAction(BulkProvenanceAction $action): void
    {
        $this->log->log('admin.bulk_action', $action->started_by ? (int) $action->started_by : null, null, ['action' => $action->action]);
    }
}
