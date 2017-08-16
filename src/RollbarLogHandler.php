<?php namespace Rollbar\Laravel;

use Exception;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use Monolog\Logger as Monolog;
use Psr\Log\AbstractLogger;
use Rollbar\RollbarLogger;

class RollbarLogHandler extends AbstractLogger
{
    /**
     * The rollbar client instance.
     *
     * @var logger
     */
    protected $logger;

    /**
     * The Laravel application.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * The minimum log level at which messages are sent to Rollbar.
     *
     * @var string
     */
    protected $level;

    /**
     * The Log levels.
     *
     * @var array
     */
    protected $levels = [
        'debug'     => Monolog::DEBUG,
        'info'      => Monolog::INFO,
        'notice'    => Monolog::NOTICE,
        'warning'   => Monolog::WARNING,
        'error'     => Monolog::ERROR,
        'critical'  => Monolog::CRITICAL,
        'alert'     => Monolog::ALERT,
        'emergency' => Monolog::EMERGENCY,
        'none'      => 1000,
    ];

    /**
     * Constructor.
     */
    public function __construct(RollbarLogger $logger, Application $app, $level = 'debug')
    {
        $this->logger = $logger;

        $this->app = $app;

        $this->level = $this->parseLevel($level ?: 'debug');
    }

    public function extendPersonWithSession(array $person)
    {
        $person['session'] = isset($person['session'])
            ? array_merge($this->app->session->all(), $person['session'])
            : $this->app->session->all();

        // User session id as user id if not set.
        if (! isset($person['id'])) {
            $person['id'] = $this->app->session->getId();
        }

        return $person;
    }

    /**
     * Log a message to Rollbar.
     *
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     */
    public function log($level, $message, array $context = [])
    {
        // Check if we want to log this message.
        if ($this->parseLevel($level) < $this->level) {
            return;
        }

        $context = $this->addContext($context);

        return $this->logger->log($level, $message, $context);
    }

    /**
     * Add Laravel specific information to the context.
     *
     * @param array $context
     */
    protected function addContext(array $context = [])
    {
        // Add session data.
        if ($this->app->session->isStarted()) {
            // Merge person context.
            if (isset($context['person']) and is_array($context['person'])) {
                $person = $context['person'];
                unset($context['person']);
            } else {
                // Add user session information.
                $config = $this->logger->extend([]);
                if (isset($config['person_fn'])) {
                    return $context;
                }
                $person = isset($config['person']) ? $config['person'] : [];
            }

            $this->logger->configure(['person' => $this->extendPersonWithSession($person)]);
        }

        return $context;
    }

    /**
     * Parse the string level into a Monolog constant.
     *
     * @param  string  $level
     * @return int
     *
     * @throws \InvalidArgumentException
     */
    protected function parseLevel($level)
    {
        if (isset($this->levels[$level])) {
            return $this->levels[$level];
        }

        throw new InvalidArgumentException('Invalid log level: ' . $level);
    }
}
