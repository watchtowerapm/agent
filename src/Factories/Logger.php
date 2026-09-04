<?php

namespace Watchtower\Laravel\Factories;

use DateTimeZone;
use Monolog\Logger as Monolog;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\Hooks\LogHandler;
use Watchtower\Laravel\Hooks\LogRecordProcessor;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class Logger
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>&array{level: LogLevel::*}  $config
     */
    public function __invoke(array $config): LoggerInterface
    {
        return new Monolog(
            name: 'watchtower',
            handlers: [
                new LogHandler(
                    watchtower: $this->watchtower,
                    level: Monolog::toMonologLevel($config['level']),
                    // There is some unexpected behaviour in the framework when
                    // using a log stack that causes monolog processors to leak
                    // and apply their side-effects to other log handlers in
                    // the stack. Instead of passing processors to the monolog
                    // instance, as you would usually expect, we pass them to
                    // our handler to apply manually. This allows us to keep
                    // the side-effects of the processors isolated to
                    // Watchtower's handler when used in a stack of handlers.
                    processors: [
                        new LogRecordProcessor($this->watchtower, 'Y-m-d H:i:s.uP'),
                        new PsrLogMessageProcessor('Y-m-d H:i:s.uP'),
                    ],
                ),
            ],
            timezone: new DateTimeZone('UTC'),
        );
    }
}
