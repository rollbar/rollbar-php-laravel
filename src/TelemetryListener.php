<?php

namespace Rollbar\Laravel;

use Exception;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Events\RouteMatched;
use Rollbar\Rollbar;
use Rollbar\Telemetry\EventType;
use Rollbar\Telemetry\EventLevel;
use Rollbar\Telemetry\Telemeter;

/**
 * This class handles Laravel events and maps them to Rollbar telemetry events.
 *
 * @since 8.1.0
 */
class TelemetryListener
{
    const BASE_EVENTS = [
        MessageLogged::class => 'logMessageHandler',
        RouteMatched::class => 'routeMatchedHandler',
        QueryExecuted::class => 'queryExecutedHandler',
    ];

    private Container $container;

    private array $config;

    /**
     * @var bool
     */
    private bool $captureLogs;
    private bool $captureRouting;
    private bool $captureQueries;
    private bool $captureDbParameters;

    /**
     * @param Container $container The Laravel application container.
     * @param array $config
     */
    public function __construct(Container $container, array $config)
    {
        $this->container = $container;
        $this->config = $config;

        $this->captureLogs = boolval($this->config['telemetry']['capture_logs'] ?? true);
        $this->captureRouting = boolval($this->config['telemetry']['capture_routing'] ?? true);
        $this->captureQueries = boolval($this->config['telemetry']['capture_db_queries'] ?? true);
        // We do not want to capture query parameters by default, the developer must explicitly enable it.
        $this->captureDbParameters = boolval($this->config['telemetry']['capture_db_query_parameters'] ?? false);
    }

    /**
     * Register the event listeners for the application.
     *
     * @param Dispatcher $dispatcher
     * @return void
     */
    public function listen(Dispatcher $dispatcher): void
    {
        foreach (self::BASE_EVENTS as $event => $handler) {
            $dispatcher->listen($event, [$this, $handler]);
        }
    }

    /**
     * Execute the event handler.
     *
     * This is used so that the handlers are not public methods.
     *
     * @param string $method The method to call.
     * @param array $args The arguments to pass to the method.
     * @return void
     */
    public function __call(string $method, array $args): void
    {
        if (!method_exists($this, $method)) {
            return;
        }

        try {
            $this->{$method}(...$args);
        } catch (Exception $e) {
            // Do nothing.
        }
    }

    /**
     * Handler for log messages.
     *
     * @param MessageLogged $message
     * @return void
     */
    protected function logMessageHandler(MessageLogged $message): void
    {
        if (null === $message->message || !$this->captureLogs) {
            return;
        }

        Rollbar::captureTelemetryEvent(
            EventType::Log,
            // Telemetry does not support all PSR-3 or RFC-5424 levels, so we need to convert them.
            Telemeter::getLevelFromPsrLevel($message->level),
            [
                'message' => $message->message,
                'context' => $message->context,
            ],
        );
    }

    protected function routeMatchedHandler(RouteMatched $matchedRoute): void
    {
        if (!$this->captureRouting) {
            return;
        }
        $routePath = $matchedRoute->route->uri();

        Rollbar::captureTelemetryEvent(
            EventType::Manual,
            EventLevel::Info,
            [
                'message' => 'Route matched',
                'route' => $routePath,
            ],
        );
    }

    protected function queryExecutedHandler(QueryExecuted $query): void
    {
        if (!$this->captureQueries) {
            return;
        }

        $meta = [
            'message' => 'Query executed',
            'query' => $query->sql,
            'time' => $query->time,
            'connection' => $query->connectionName,
        ];

        if ($this->captureDbParameters) {
            $meta['bindings'] = $query->bindings;
        }

        Rollbar::captureTelemetryEvent(EventType::Manual, EventLevel::Info, $meta);
    }
}
