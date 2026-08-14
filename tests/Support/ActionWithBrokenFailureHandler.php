<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Spatie\QueueableAction\QueueableAction;
use Throwable;

/**
 * An action whose failure handler is itself broken.
 *
 * Stands for the whole class of ways recording a failure can fail: a collaborator that throws
 * while the container builds it, a database that is unreachable at exactly the wrong moment, a
 * genuine bug in the handler. What matters is not which one, but that none of them is allowed out
 * of ActionJob::failed() - the caller is Job::fail(), which has no catch, and is already handling
 * an exception when it gets there.
 */
class ActionWithBrokenFailureHandler
{
    use QueueableAction;

    public function execute(): void
    {
        //
    }

    public function failed(?Throwable $exception): void
    {
        throw new RuntimeException('the failure handler is broken too');
    }
}
