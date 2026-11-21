<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DurationLabelTest extends TestCase
{
    use RefreshDatabase;

    private function route(array $attrs): HikingRoute
    {
        return HikingRoute::create($attrs + [
            'gpx_file_path' => 'gpx_files/x.gpx', 'distance_km' => 5.54, 'elevation_gain_m' => 1368,
            'naismith_duration_hour' => 3.39, 'average_grade_pct' => 24.7,
        ]);
    }

    public function test_summit_routes_say_time_to_summit_and_others_say_walking_time(): void
    {
        $summit = $this->route(['name' => 'Mt. Merbabu via Selo']);
        $crater = $this->route(['name' => 'Kawah Ijen via Sunrise View Trek', 'summit_route' => false]);

        app()->setLocale('id');
        $this->assertSame('Waktu ke puncak', $summit->fresh()->duration_label);
        $this->assertSame('Waktu jalan', $crater->fresh()->duration_label);

        $this->withCookie('rs_lang', 'id')->get(route('routes.show', $summit))->assertOk()
            ->assertSee('Waktu ke puncak')->assertSee('belum termasuk istirahat dan beban carrier');
        $this->withCookie('rs_lang', 'id')->get(route('routes.show', $crater))->assertOk()
            ->assertSee('Waktu jalan')->assertDontSee('Waktu ke puncak');
    }
}
