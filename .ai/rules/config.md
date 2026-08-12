---
paths:
  - 'config/**'
---

# Config

## AUTH_ENABLED is fail-safe on purpose, and PHPUnit mangles boolean env values
config/auth.php uses `env('AUTH_ENABLED', true) !== false` rather than a plain env() or a cast. Only a literal AUTH_ENABLED=false disables authentication; an empty, misspelled or unparseable value leaves it on. The failure mode of this switch is "the app is open to everyone", so it must never fail permissive.

Related trap: PHPUnit casts <env name="X" value="true"/> to a real boolean and putenv() stringifies it, so env('X') returns the string "1" under test but a bool in the app. Do not assert on raw env() values in tests, and do not assume a config value built from env() has the same type in both.

## The summary timeout comes from App\Support\SummaryBudget, not from config()
A config file cannot call config(): the repository is only bound once every file has been read. That constraint is about config() alone - the autoloader is already running, so a config file CAN name application code, which is how both App\Enums\Queue and App\Support\SummaryBudget are used from config/queue.php and config/horizon.php.

Three files need the summarising budget and must stay in this order:

  job timeout < supervisor timeout < retry_after
  (config/summaries)  (config/horizon)   (config/queue)

Out of order, a worker is still running a job the queue has already handed to somebody else, and a summary is a paid model call. All three read SummaryBudget::seconds(), so do not re-derive the arithmetic in a config file - that is what this class replaced. SummariseVideoTest asserts the ordering and re-reads all three files through configWithEnv.
