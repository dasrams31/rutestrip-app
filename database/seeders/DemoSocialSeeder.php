<?php

namespace Database\Seeders;

use App\Models\HikingRoute;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sample Linimasa data for a local or throwaway database (screenshots, trying the app).
 * NOT called by DatabaseSeeder. Run on purpose only:
 *   php artisan db:seed --class=DemoSocialSeeder
 * Creates three demo hikers (demo-*@example.test, random passwords), follows,
 * a few posts of each type, likes and comments. Running it twice adds nothing new.
 */
class DemoSocialSeeder extends Seeder
{
    public function run(): void
    {
        $people = [
            ['name' => 'Rama Demo', 'email' => 'demo-rama@example.test', 'bio' => 'Suka jalur Merbabu dan Sindoro.'],
            ['name' => 'Sari Demo', 'email' => 'demo-sari@example.test', 'bio' => 'Mendaki sambil memotret kabut pagi.'],
            ['name' => 'Budi Demo', 'email' => 'demo-budi@example.test', 'bio' => null],
        ];
        $users = [];
        foreach ($people as $p) {
            $users[] = User::firstOrCreate(['email' => $p['email']], [
                'name' => $p['name'], 'bio' => $p['bio'], 'password' => Hash::make(Str::random(32)),
            ]);
        }
        [$rama, $sari, $budi] = $users;

        if (Post::whereIn('user_id', collect($users)->pluck('id'))->exists()) {
            return;
        }

        foreach ([[$rama, $sari], [$rama, $budi], [$sari, $rama]] as [$from, $to]) {
            DB::table('follows')->insertOrIgnore(['follower_id' => $from->id, 'followed_id' => $to->id, 'created_at' => now()]);
        }

        $route = HikingRoute::orderBy('id')->first();
        $track = [];
        for ($i = 0; $i <= 60; $i++) {
            $t = $i / 60;
            $track[] = [round(-7.4950 + 0.045 * $t + 0.004 * sin($t * 9), 5), round(110.4400 - 0.012 * $t + 0.006 * cos($t * 7), 5)];
        }

        $posts = [
            Post::create(['user_id' => $sari->id, 'type' => 'status', 'body' => "Air di pos 2 masih mengalir.\nBawa botol kosong saja dari basecamp.",
                'place' => ['lat' => -7.4712, 'lng' => 110.4361, 'label' => 'Pos 2'], 'route_id' => $route?->id]),
            Post::create(['user_id' => $rama->id, 'type' => 'recording', 'body' => 'Rekaman naik pagi ini, jalur kering.',
                'track' => $track, 'stats' => ['distance_km' => 7.8, 'gain_m' => 1240, 'moving_s' => 16200], 'route_id' => $route?->id]),
            Post::create(['user_id' => $budi->id, 'type' => 'status', 'body' => 'Ada yang tahu jam buka basecamp hari Senin?']),
            Post::create(['user_id' => $sari->id, 'type' => 'story', 'body' => 'Kabut tebal di sabana, jalan pelan.', 'expires_at' => now()->addHours(20)]),
            Post::create(['user_id' => $rama->id, 'type' => 'story', 'body' => 'Sudah di basecamp, turun sore.', 'expires_at' => now()->addHours(10)]),
        ];

        foreach ([[$posts[0], $rama], [$posts[0], $budi], [$posts[1], $sari]] as [$post, $user]) {
            DB::table('post_likes')->insertOrIgnore(['post_id' => $post->id, 'user_id' => $user->id, 'created_at' => now()]);
        }
        PostComment::create(['post_id' => $posts[0]->id, 'user_id' => $rama->id, 'body' => 'Makasih infonya, berangkat Sabtu.']);
        PostComment::create(['post_id' => $posts[1]->id, 'user_id' => $sari->id, 'body' => 'Cepat juga, start jam berapa?']);

        foreach ($posts as $post) {
            DB::table('posts')->where('id', $post->id)->update([
                'likes_count'    => DB::table('post_likes')->where('post_id', $post->id)->count(),
                'comments_count' => PostComment::where('post_id', $post->id)->count(),
            ]);
        }
    }
}
