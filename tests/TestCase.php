<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Forget every resolved guard, so the NEXT request re-authenticates from scratch.
     *
     * Laravel reuses one application instance across the requests in a single test, and the
     * auth guard caches the user it resolved. That makes any assertion of the form "do X,
     * then prove the old credential no longer works" silently pass: the second request never
     * re-reads the token, it reuses the user from the first.
     *
     * Every test that revokes a token or changes a role needs this between requests, or it
     * asserts nothing.
     */
    protected function forgetAuth(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }
}
