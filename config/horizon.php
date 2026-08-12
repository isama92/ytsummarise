<?php

use App\Enums\Queue;
use App\Support\SummaryBudget;
use Illuminate\Support\Str;

/*
 * How long the summarising worker gets, which is the job's own budget rather than a number
 * chosen here. Three values have to stay in this order or a paid job is worked twice:
 *
 *     job timeout  <  supervisor timeout  <  connection retry_after
 *      (summaries.timeout)   (below)        (queue.connections.summaries)
 *
 * The job is what actually stops - SummariseVideo sets $timeout from config('summaries.timeout')
 * and Laravel prefers a job's own timeout over the worker's. The supervisor's is the fallback
 * under it, and separately the grace Horizon gives a terminating worker to finish what it is
 * holding before it stops the process outright
 * (ProcessPool::stopTerminatingProcessesThatAreHanging). So it sits above the job's budget
 * rather than at it, and below retry_after, which is that plus sixty.
 *
 * Read through SummaryBudget rather than config('summaries.timeout') because a config file
 * cannot call config(): the repository is only bound once every file has been read. A class it
 * can reach, which is also how App\Enums\Queue is used below.
 */
$summaryTimeout = SummaryBudget::seconds(
    env('SUMMARY_MODEL_TIMEOUT'),
    env('SUMMARY_TRANSCRIPT_TIMEOUT'),
    env('SUMMARY_TIMEOUT'),
);

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    | Thresholds rather than the stock single entry, because the two connections here are not
    | comparable. A minute of waiting on `high` is a fault; a minute of waiting on `summaries`
    | is one video finishing ahead of another, which is what a serial queue is for. The
    | summaries threshold is set past a full job's budget so only a genuinely stuck worker
    | trips it.
    |
    | Nothing routes these anywhere yet - see Horizon::routeMailNotificationsTo - so today
    | this only decides when LongWaitDetected fires.
    |
    */

    'waits' => [
        'redis:'.Queue::High->value => 30,
        'redis:'.Queue::Default->value => 60,
        'redis:'.Queue::Low->value => 300,
        'summaries:'.Queue::Summaries->value => $summaryTimeout + 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    | One value is off the stock defaults. `pending` is a week rather than an hour, because an
    | hour is a plausible length for a single summary and the summaries queue is worked one job
    | at a time: a second video asked for while the first is running legitimately sits pending
    | for as long as the first one takes. At sixty minutes Horizon would trim those out from
    | under the dashboard, and the queue would look empty while it was not.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 10080,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [

        /*
         * The three general-purpose queues, in one supervisor rather than three.
         *
         * balance is false, which is what makes the order of that array mean something: with
         * `auto` Horizon ignores it entirely and allocates workers by load, so `high` would be
         * a name rather than a priority. False processes them strictly in the order listed and
         * still scales the process count between minProcesses and maxProcesses.
         *
         * timeout has to stay under the connection's retry_after of 90, or a worker still
         * running a job is one the queue has already handed to somebody else. Jobs that set
         * their own $timeout override this; nothing dispatches here yet, so this is the
         * budget a future job gets by saying nothing.
         *
         * maxTime and maxJobs recycle each worker rather than letting one run for the life of
         * the container, which is what `queue:work --max-time=3600` used to do in compose.yml.
         */
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => [Queue::High->value, Queue::Default->value, Queue::Low->value],
            'balance' => false,
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 3600,
            'maxJobs' => 100,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        /*
         * Summarising, one job at a time, on its own connection.
         *
         * maxProcesses of 1 with minProcesses of 1 is the whole point: the work is a long
         * paid model call, and two of them at once is two of them competing for the same
         * machine to do something nobody asked for twice. balance false rather than auto
         * because auto exists to scale, and this must not.
         *
         * tries agrees with the job's own $tries of 1. Horizon defaults to one attempt anyway
         * and the job's property wins regardless, but a supervisor quietly retrying a paid
         * job is not a thing to leave implied.
         *
         * nice above the default so a summary that runs for an hour yields to the web
         * container when they share a host.
         */
        'supervisor-summaries' => [
            'connection' => 'summaries',
            'queue' => [Queue::Summaries->value],
            'balance' => false,
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 50,
            'memory' => 256,
            'tries' => 1,
            'timeout' => $summaryTimeout + 30,
            'nice' => 10,
        ],
    ],

    /*
     * Only the two environments this application runs in. `environments` patches `defaults`
     * rather than replacing it, so these list what differs and nothing else.
     *
     * Local gets one process on each because a development machine running three workers
     * against one model endpoint is not testing anything the first one does not.
     *
     * There is no `testing` entry and none is needed: this key is read by the `horizon`
     * command alone, and the suite never starts a master supervisor.
     */
    'environments' => [
        'production' => [
            'supervisor-default' => [
                'maxProcesses' => 3,
            ],
            'supervisor-summaries' => [
                'maxProcesses' => 1,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'maxProcesses' => 1,
            ],
            'supervisor-summaries' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
