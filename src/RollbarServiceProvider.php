<?php namespace Rollbar\Laravel;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Rollbar\Rollbar;
use Rollbar\RollbarLogger;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class RollbarServiceProvider extends ServiceProvider
{
    /**
     * The telemetry event listener.
     */
    protected TelemetryListener $telemetryListener;

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        // Don't register rollbar if it is not configured.
        if ($this->stop() === true) {
            return;
        }

        $this->app->singleton(RollbarLogger::class, function (Application $app) {
            $config = $this->getConfigs($app);

            $handleException = (bool)Arr::pull($config, 'handle_exception');
            $handleError = (bool)Arr::pull($config, 'handle_error');
            $handleFatal = (bool)Arr::pull($config, 'handle_fatal');
            Rollbar::init($config, $handleException, $handleError, $handleFatal);

            return Rollbar::logger();
        });

        $this->app->singleton(MonologHandler::class, function (Application $app) {

            $level = static::config('level', 'debug');

            $handler = new MonologHandler($app[RollbarLogger::class], $level);
            $handler->setApp($app);

            return $handler;
        });
    }

    /**
     * Boot is called after all services are registered.
     *
     * This is where we can start listening for events.
     *
     * @param RollbarLogger $logger This parameter is injected by the service container, and is required to ensure that
     *                              the Rollbar logger is initialized.
     * @return void
     *
     * @since 8.1.0
     */
    public function boot(RollbarLogger $logger): void
    {
        // Set up telemetry if it is enabled.
        if (null !== Rollbar::getTelemeter()) {
            $this->setupTelemetry($this->getConfigs($this->app));
        }
    }

    /**
     * Check if we should prevent the service from registering.
     *
     * @return boolean
     */
    public function stop(): bool
    {
        $level = static::config('level');

        $token = static::config('access_token');

        $hasToken = empty($token) === false;

        return $hasToken === false || $level === 'none';
    }

    /**
     * Return a rollbar logging config.
     *
     * @param string $key The config key to lookup.
     * @param mixed $default The default value to return if the config is not found.
     *
     * @return mixed
     */
    protected static function config(string $key = '', mixed $default = null): mixed
    {
        $envKey = 'ROLLBAR_' . strtoupper($key);

        if ($envKey === 'ROLLBAR_ACCESS_TOKEN') {
            $envKey = 'ROLLBAR_TOKEN';
        }

        $logKey = empty($key) ? 'logging.channels.rollbar' : 'logging.channels.rollbar.' . $key;

        return getenv($envKey) ?: Config::get($logKey, $default);
    }

    /**
     * Returns the Rollbar configuration.
     *
     * @param Application $app The Laravel application.
     * @return array
     *
     * @since 8.1.0
     *
     * @throw InvalidArgumentException If the Rollbar access token is not configured.
     */
    public function getConfigs(Application $app): array
    {
        $defaults = [
            'environment' => $app->environment(),
            'root' => base_path(),
            'handle_exception' => true,
            'handle_error' => true,
            'handle_fatal' => true,
        ];

        $config = array_merge($defaults, $app['config']->get('logging.channels.rollbar', []));
        $config['access_token'] = static::config('access_token');

        if (empty($config['access_token'])) {
            throw new InvalidArgumentException('Rollbar access token not configured');
        }
        // Convert a request for the Rollbar agent to handle the logs to
        // the format expected by `Rollbar::init`.
        // @see https://github.com/rollbar/rollbar-php-laravel/issues/85
        $handler = Arr::get($config, 'handler', MonologHandler::class);
        if ($handler === AgentHandler::class) {
            $config['handler'] = 'agent';
        }
        $config['framework'] = 'laravel ' . $app->version();
        return $config;
    }

    /**
     * Sets up the telemetry event listeners.
     *
     * @param array $config
     * @return void
     *
     * @since 8.1.0
     */
    protected function setupTelemetry(array $config): void
    {
        $this->telemetryListener = new TelemetryListener($this->app, $config);

        try {
            $dispatcher = $this->app->make(Dispatcher::class);
        } catch (BindingResolutionException $e) {
            return;
        }

        $this->telemetryListener->listen($dispatcher);
    }
}
