<?php

declare(strict_types=1);

namespace Tests\Support;

use Spatie\QueueableAction\QueueableAction;

/**
 * An action that says nothing about uniqueness, which is the ordinary case.
 *
 * There is only one queueable action in the application and it does key itself, so this exists
 * to pin what happens to one that does not: the job class is chosen globally, so an action with
 * no opinion still arrives as an App\Jobs\ActionJob and must not be quietly serialised against
 * every other dispatch of itself.
 */
class UnkeyedAction
{
    use QueueableAction;

    public function execute(): void
    {
        //
    }
}
