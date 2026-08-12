<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        /*
         * Used by the test suite only; phpunit.xml pins DB_CONNECTION=sqlite.
         */
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'ytsummarise'),
            'username' => env('DB_USERNAME', 'ytsummarise'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis carries the queue, Horizon's own bookkeeping, the sessions and the cache. Four
    | things, deliberately across two databases rather than one.
    |
    | The split is the whole reason this block is not the stock single connection. `cache:clear`
    | is a FLUSHDB, and it is a command people run without thinking twice - reasonably, because
    | on this application it used to mean a DELETE against a cache table and nothing else. On one
    | shared database it would now take every queued summary, every one of Horizon's metrics and
    | every signed-in session with it. So the cache gets a database of its own and nothing else
    | lives there.
    |
    | Sessions sit on `default` rather than beside the cache for that same reason, and they get
    | there on their own: the redis session driver borrows the redis CACHE store and then
    | re-points it at config('session.connection'), and a null there resolves to `default`. So
    | the safe arrangement is also the one you get by leaving it alone - SESSION_CONNECTION is
    | set in .env.example to say so out loud rather than to change anything.
    |
    | phpredis rather than predis, matching what the Dockerfile installs and what Horizon
    | recommends. No cluster: Horizon does not support Redis Cluster.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel'), '_').'_database_'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        /*
         * The queue, Horizon and the sessions. config/horizon.php's `use` names this one, and
         * queue.connections.redis and queue.connections.summaries both point here - Horizon can
         * only see the jobs it is watching if they are all on the same database.
         */
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        /*
         * The cache store, and nothing else. See above for why it is alone here.
         */
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
