<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL may carry a sub-directory (e.g. http://localhost/ecommerce) which would
        // prepend that segment to every test request and break API route matching.
        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
    }
}
