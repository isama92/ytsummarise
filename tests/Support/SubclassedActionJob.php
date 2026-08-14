<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Jobs\ActionJob;

/**
 * A job class extending the application's, which nothing does yet.
 *
 * It exists so that the serialisation trap in doing so is a failing test rather than a discovery.
 * ReflectionClass::getProperties() does not report a parent's private properties, so a private
 * $uniqueKey would be left out of this class's payload and the restored job would find it
 * uninitialised - throwing when Laravel releases the lock, which happens after the work is done.
 */
class SubclassedActionJob extends ActionJob
{
    //
}
