<?php

namespace Rollbar\Laravel\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Rollbar\Rollbar;
use Rollbar\Telemetry\EventLevel;

class TelemetryListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Rollbar::getTelemeter()?->clearQueue();
    }

    public function testTelemetryDisabled(): void
    {
        $this->refreshApplicationWithConfig([
            'logging.channels.rollbar.telemetry' => false,
        ]);

        self::assertFalse($this->app['config']->get('logging.channels.rollbar.telemetry'));
        self::assertNull(Rollbar::getTelemeter());
    }

    public function testTelemetryCapturesLaravelLogs(): void
    {
        $this->refreshApplicationWithConfig([
            'logging.channels.rollbar.telemetry.capture_logs' => true,
        ]);

        self::assertTrue($this->app['config']->get('logging.channels.rollbar.telemetry.capture_logs'));

        $this->app['events']->dispatch(new MessageLogged(
            level: 'debug',
            message: 'telemetry test message',
            context: ['foo' => 'bar']
        ));

        $telemetryEvents = Rollbar::getTelemeter()->copyEvents();
        $lastItem = array_pop($telemetryEvents);

        self::assertSame(EventLevel::Debug, $lastItem->level);
        self::assertSame('telemetry test message', $lastItem->body->message);
        self::assertSame(['context' => ['foo' => 'bar']], $lastItem->body->extra);
    }

    public function testTelemetryDoesNotCaptureLaravelLogsWhenDisabled(): void
    {
        $this->refreshApplicationWithConfig([
            'logging.channels.rollbar.telemetry.capture_logs' => false,
        ]);

        self::assertFalse($this->app['config']->get('logging.channels.rollbar.telemetry.capture_logs'));

        $this->app['events']->dispatch(new MessageLogged(
            level: 'debug',
            message: 'telemetry test message',
            context: ['foo' => 'bar']
        ));

        self::assertEmpty(Rollbar::getTelemeter()->copyEvents());
    }

    public function testTelemetryCapturesRouteMatched(): void
    {
        $this->refreshApplicationWithConfig([
            'logging.channels.rollbar.telemetry.capture_routing' => true,
        ]);

        self::assertTrue($this->app['config']->get('logging.channels.rollbar.telemetry.capture_routing'));

        $this->app['events']->dispatch(new RouteMatched(
            route: new Route(
                methods: ['GET'],
                uri: 'test',
                action: (fn() => 'test')(...),
            ),
            request: new Request(),
        ));

        $telemetryEvents = Rollbar::getTelemeter()->copyEvents();
        $lastItem = array_pop($telemetryEvents);

        self::assertSame(EventLevel::Info, $lastItem->level);
        self::assertSame('Route matched', $lastItem->body->message);
        self::assertSame(['route' => 'test'], $lastItem->body->extra);
    }

    public function testTelemetryDoesNotCaptureRouteMatchedWhenDisabled(): void
    {
        $this->refreshApplicationWithConfig([
            'logging.channels.rollbar.telemetry.capture_routing' => false,
        ]);

        self::assertFalse($this->app['config']->get('logging.channels.rollbar.telemetry.capture_routing'));

        $this->app['events']->dispatch(new RouteMatched(
            route: new Route(
                methods: ['GET'],
                uri: 'test',
                action: (fn() => 'test')(...),
            ),
            request: new Request(),
        ));

        self::assertEmpty(Rollbar::getTelemeter()->copyEvents());
    }

    public function testTelemetryCapturesQueryExecuted(): void
    {
        $this->refreshApplicationWithConfig([
            'logging.channels.rollbar.telemetry.capture_db_queries' => true,
            'logging.channels.rollbar.telemetry.capture_db_query_parameters' => true,
        ]);

        self::assertTrue($this->app['config']->get('logging.channels.rollbar.telemetry.capture_db_queries'));
        self::assertTrue($this->app['config']->get('logging.channels.rollbar.telemetry.capture_db_query_parameters'));

        $this->app['events']->dispatch(new QueryExecuted(
            sql: 'SELECT * FROM test WHERE id = ?',
            bindings: [1],
            time: 0.1,
            connection: new Connection(
                pdo: (fn() => 'test')(...),
                config: ['name' => 'connection_name'],
            ),
        ));

        $telemetryEvents = Rollbar::getTelemeter()->copyEvents();
        $lastItem = array_pop($telemetryEvents);

        self::assertSame(EventLevel::Info, $lastItem->level);
        self::assertSame('Query executed', $lastItem->body->message);
        self::assertSame([
            'query' => 'SELECT * FROM test WHERE id = ?',
            'time' => 0.1,
            'connection' => 'connection_name',
            'bindings' => [1],
        ], $lastItem->body->extra);
    }

    public function testTelemetryDoesNotCaptureQueryExecutedWhenDisabled(): void
    {
        $this->refreshApplicationWithConfig([
            'logging.channels.rollbar.telemetry.capture_db_queries' => false,
        ]);

        self::assertFalse($this->app['config']->get('logging.channels.rollbar.telemetry.capture_db_queries'));

        $this->app['events']->dispatch(new QueryExecuted(
            sql: 'SELECT * FROM test WHERE id = ?',
            bindings: [1],
            time: 0.1,
            connection: new Connection(
                pdo: (fn() => 'test')(...),
                config: ['name' => 'connection_name'],
            ),
        ));

        self::assertEmpty(Rollbar::getTelemeter()->copyEvents());
    }

    public function testTelemetryDoesNotCaptureQueryParametersWhenDisabled(): void
    {
        $this->refreshApplicationWithConfig([
            'logging.channels.rollbar.telemetry.capture_db_queries' => true,
            'logging.channels.rollbar.telemetry.capture_db_query_parameters' => false,
        ]);

        self::assertTrue($this->app['config']->get('logging.channels.rollbar.telemetry.capture_db_queries'));
        self::assertFalse($this->app['config']->get('logging.channels.rollbar.telemetry.capture_db_query_parameters'));

        $this->app['events']->dispatch(new QueryExecuted(
            sql: 'SELECT * FROM test WHERE id = ?',
            bindings: [1],
            time: 0.1,
            connection: new Connection(
                pdo: (fn() => 'test')(...),
                config: ['name' => 'connection_name'],
            ),
        ));

        $telemetryEvents = Rollbar::getTelemeter()->copyEvents();
        $lastItem = array_pop($telemetryEvents);

        self::assertSame(EventLevel::Info, $lastItem->level);
        self::assertSame('Query executed', $lastItem->body->message);
        self::assertSame([
            'query' => 'SELECT * FROM test WHERE id = ?',
            'time' => 0.1,
            'connection' => 'connection_name',
        ], $lastItem->body->extra);
    }
}
