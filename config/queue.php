<?php

use App\Enums\Queue;
use App\Support\SummaryBudget;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "redis", "beanstalkd", "deferred",
    |          "background", "failover", "null"
    |
    | No "database" connection: the jobs table is gone, and Horizon only supervises redis
    | connections anyway. A connection naming a table that does not exist is a trap rather
    | than a fallback, so it is not kept for one.
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        /*
         * The three general-purpose queues, worked in the order config/horizon.php lists
         * them. REDIS_QUEUE names the one a job with no ->onQueue() lands on, and it has to
         * stay a queue a supervisor actually works: a job dispatched onto a queue nobody
         * reads does not fail, it simply never happens.
         */
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', Queue::Default->value),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        /*
         * Summarising has its own connection because retry_after is a property of the
         * connection rather than of the job, and a step of one can take ten minutes.
         * Leaving it on `redis` would mean every future job - a webhook, an email -
         * waiting that long to be picked up again after a worker died, to accommodate
         * one slow job. Its own queue name too, so a worker on one connection cannot
         * reserve the other's jobs off the shared Redis database.
         *
         * retry_after has to stay above the timeout of the longest single job, or the worker
         * reserves one that is still running and a video is summarised twice at a model call
         * each. The longest single job is a step of the chain rather than the whole attempt,
         * which is why this reads stepSeconds(): measuring against the sum would leave a dead
         * worker's job unreserved for the better part of an hour. Read through SummaryBudget
         * rather than config('summaries.step_timeout') because a config file cannot call
         * config() at all - the repository is only bound once every file has been read. A
         * class it can reach; a config value it cannot.
         *
         * The minute on top is the margin, and config/horizon.php puts the supervisor's own
         * timeout inside it. The order that matters is step < supervisor < this.
         */
        'summaries' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => Queue::Summaries->value,
            'block_for' => null,
            'retry_after' => SummaryBudget::stepSeconds(
                env('SUMMARY_MODEL_TIMEOUT'),
                env('SUMMARY_TRANSCRIPT_TIMEOUT'),
            ) + 60,
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'redis',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | Summarising is one named batch holding a chain of five steps, which is what puts a
    | video's progress on Horizon's Batches tab instead of leaving it as one job that is
    | either running or not for the better part of an hour.
    |
    | In Postgres while the jobs themselves are in Redis, and that is the only shape on
    | offer: this key names a database connection. It suits the job anyway. A batch's row
    | outlives the jobs it counts, `queue:prune-batches` decides when it goes rather than
    | an eviction policy, and a Redis flush leaves a finished batch legible.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],

];
