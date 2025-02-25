<?php

namespace Rollbar\Laravel\Tests;

use Illuminate\Foundation\Application;
use Mockery;
use Rollbar\Laravel\RollbarServiceProvider;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected string $access_token = 'B42nHP04s06ov18Dv8X7VI4nVUs6w04X';

    protected array $appConfig = [];

    protected function setUp(): void
    {
        putenv('ROLLBAR_TOKEN=' . $this->access_token);

        parent::setUp();

        Mockery::close();
    }

    /**
     * Set up the environment.
     *
     * @param $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        $configs = array_merge(
            [
                'logging.channels.rollbar.driver' => 'rollbar',
                'logging.channels.rollbar.level' => 'debug',
                'logging.channels.rollbar.access_token' => env('ROLLBAR_TOKEN'),
            ],
            $this->appConfig,
        );
        foreach ($configs as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    /**
     * @param Application $app The Laravel application.
     * @return class-string[] The service providers to register.
     */
    protected function getPackageProviders($app): array
    {
        return [RollbarServiceProvider::class];
    }

    /**
     * Creates a new Laravel application with the given configuration and sets it as the active application.
     *
     * @param array $config The configuration to use.
     * @return void
     */
    protected function refreshApplicationWithConfig(array $config): void
    {
        $this->appConfig = $config;
        $this->refreshApplication();
    }
}
