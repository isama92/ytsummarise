---
paths:
  - 'config/**'
---

# Config

## AUTH_ENABLED is fail-safe on purpose, and PHPUnit mangles boolean env values
config/auth.php uses `env('AUTH_ENABLED', true) !== false` rather than a plain env() or a cast. Only a literal AUTH_ENABLED=false disables authentication; an empty, misspelled or unparseable value leaves it on. The failure mode of this switch is "the app is open to everyone", so it must never fail permissive.

Related trap: PHPUnit casts <env name="X" value="true"/> to a real boolean and putenv() stringifies it, so env('X') returns the string "1" under test but a bool in the app. Do not assert on raw env() values in tests, and do not assume a config value built from env() has the same type in both.

## The summary timeout is derived in three config files
A config file cannot call config(): the repository is only bound once every file has been read. So the same arithmetic for the summarising budget is written out three times - config/summaries.php (the job's timeout), config/queue.php (connections.summaries.retry_after) and config/horizon.php (defaults.supervisor-summaries.timeout). Change a step budget and all three must move together, in this order:

  job timeout < supervisor timeout < retry_after

Out of order, a worker is still running a job the queue has already handed to somebody else, and a summary is a paid model call. SummariseVideoTest asserts the ordering and re-reads all three files through configWithEnv to prove they move together.
