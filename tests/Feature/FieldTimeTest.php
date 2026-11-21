<?php

namespace Tests\Feature;

use App\Models\HikingRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_range_is_naismith_times_the_factors_in_half_hours(): void
    {
        $route = new HikingRoute(['naismith_duration_hour' => 3.39]); // Merbabu via Selo
        $this->assertSame([4.5, 6.5], $route->field_hours_range);     // 4.75 down, 6.10 up

        $this->assertSame([0.5, 1.0], (new HikingRoute(['naismith_duration_hour' => 0.3]))->field_hours_range);
        $this->assertNull((new HikingRoute(['naismith_duration_hour' => null]))->field_hours_range);
    }

    public function test_route_page_labels_time_up_and_shows_the_field_range(): void
    {
        $route = HikingRoute::create([
            'name' => 'Mt. Merbabu via Selo', 'gpx_file_path' => 'gpx_files/x.gpx',
            'distance_km' => 5.54, 'elevation_gain_m' => 1368, 'naismith_duration_hour' => 3.39, 'average_grade_pct' => 24.7,
        ]);

        $this->withCookie('rs_lang', 'id')->get(route('routes.show', $route))
            ->assertOk()
            ->assertSee('Waktu naik')
            ->assertSee('4,5–6,5 jam');
    }
}
