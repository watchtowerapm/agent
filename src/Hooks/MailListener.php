<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class MailListener
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(MessageSending|MessageSent $event): void
    {
        try {
            $this->watchtower->mail($event);
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}
