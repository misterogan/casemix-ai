<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Modul lama (berkas klaim/impor) dimatikan di produksi; test-nya tetap dijalankan.
        config(['casemix.legacy_claims' => true]);
    }
}
