<?php

namespace Watchtower\Laravel\Hooks;

use Illuminate\Log\Context\Repository;
use Throwable;
use Watchtower\Laravel\Core;
use Watchtower\Laravel\State\CommandState;
use Watchtower\Laravel\State\RequestState;

/**
 * @internal
 */
final class ContextDehydratingHandler
{
    /**
     * @param  Core<RequestState|CommandState>  $watchtower
     */
    public function __construct(
        private Core $watchtower,
    ) {
        //
    }

    public function __invoke(Repository $context): void
    {
        try {
            if (($context->getHidden('nightwatch_user_id') ?? '') === '') {
                $context->addHidden('nightwatch_user_id', $this->watchtower->executionState->user->resolvedUserId());
            }
        } catch (Throwable $e) {
            $this->watchtower->report($e, handled: true);
        }
    }
}
