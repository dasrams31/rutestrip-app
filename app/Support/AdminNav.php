<?php

namespace App\Support;

use App\Models\CommunityMessage;
use App\Models\HikingRoute;

/** Tabs under the admin page header (partials/rs-head "tabs"). */
class AdminNav
{
    public static function tabs(string $active): array
    {
        // Chat messages plus Linimasa posts and comments waiting on /admin/moderasi.
        $reports = CommunityMessage::whereNotNull('reported_at')->count()
            + \App\Models\Post::whereNotNull('reported_at')->count()
            + \App\Models\PostComment::whereNotNull('reported_at')->whereHas('post')->count();
        $pending = HikingRoute::withoutGlobalScope('published')->where('review_status', HikingRoute::PENDING)->count();

        $tabs = [
            ['key' => 'dashboard', 'href' => route('admin.dashboard'), 'label' => __('Dataset')],
            ['key' => 'users', 'href' => route('admin.users.index'), 'label' => __('Pengguna')],
            ['key' => 'activity', 'href' => route('admin.activity'), 'label' => __('Aktivitas')],
            ['key' => 'logs', 'href' => route('admin.logs'), 'label' => __('Log')],
            ['key' => 'moderation', 'href' => route('admin.moderation'), 'label' => __('Moderasi'), 'count' => $reports,
                'count_label' => __(':count laporan menunggu', ['count' => $reports])],
            ['key' => 'submissions', 'href' => route('admin.submissions'), 'label' => __('Kiriman'), 'count' => $pending,
                'count_label' => __(':count kiriman menunggu', ['count' => $pending])],
        ];

        return array_map(fn ($t) => $t + ['active' => $t['key'] === $active], $tabs);
    }
}
