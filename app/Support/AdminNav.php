<?php

namespace App\Support;

use App\Models\CommunityMessage;

/** Tabs under the admin page header (partials/rs-head "tabs"). */
class AdminNav
{
    public static function tabs(string $active): array
    {
        $reports = CommunityMessage::whereNotNull('reported_at')->count();

        $tabs = [
            ['key' => 'dashboard', 'href' => route('admin.dashboard'), 'label' => __('Dataset')],
            ['key' => 'users', 'href' => route('admin.users.index'), 'label' => __('Pengguna')],
            ['key' => 'activity', 'href' => route('admin.activity'), 'label' => __('Aktivitas')],
            ['key' => 'logs', 'href' => route('admin.logs'), 'label' => __('Log')],
            ['key' => 'moderation', 'href' => route('admin.moderation'), 'label' => __('Moderasi'), 'count' => $reports,
                'count_label' => __(':count laporan menunggu', ['count' => $reports])],
        ];

        return array_map(fn ($t) => $t + ['active' => $t['key'] === $active], $tabs);
    }
}
