<?php

use App\Jobs\ActionJob;

/*
 * The misspelling in this file's name is upstream, not a typo here: the package publishes
 * config/queuableaction.php and reads config('queuableaction.job_class'). Renaming it to match
 * the package's own name would leave the key unread and the default job class in place, which
 * fails silently - every action would queue perfectly well without a lock or a tag.
 */

return [
    /*
     * The job class that will be dispatched.
     *
     * One value for every action, which is the package's design rather than a choice made here,
     * so anything dispatched with ->onQueue()->execute() arrives as an App\Jobs\ActionJob - ours,
     * not the identically named one it extends. What it adds, and why an action that does not
     * want a lock still comes through it unharmed, is documented on the class.
     */
    'job_class' => ActionJob::class,
];
