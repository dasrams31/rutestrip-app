<?php

namespace Tests\Feature;

use Tests\TestCase;

class IntroductionPageTest extends TestCase
{
    public function test_every_exported_slide_has_its_image_and_is_listed_on_the_page(): void
    {
        $slides = json_decode(file_get_contents(resource_path('data/introduction.json')), true)['slides'];
        $this->assertNotEmpty($slides);

        $response = $this->get('/introduction')->assertOk();
        foreach ($slides as $slide) {
            $file = sprintf('images/introduction/slide-%02d.webp', $slide['n']);
            $this->assertFileExists(public_path($file));
            $response->assertSee($file, false)->assertSee($slide['title']);
        }
    }

    public function test_the_page_is_not_offered_to_search_engines_and_shows_no_speaker_notes(): void
    {
        $this->get('/introduction')->assertOk()
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertDontSee('Durasi sekitar');
    }
}
