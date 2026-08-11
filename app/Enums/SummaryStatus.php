<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a single summary.
 *
 * Failed exists so the frontend has something to stop polling on. Without it a job
 * that dies leaves the page asking for an answer that is never coming.
 */
enum SummaryStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
