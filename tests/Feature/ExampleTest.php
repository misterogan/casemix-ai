<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // Semua halaman butuh login; tamu diarahkan ke halaman masuk.
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();
    }
}
