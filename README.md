# ytsummarise

A simple app where you paste a YouTube video and it will give you a summary.

## Summarising

Three steps, and only the last two involve a model.

1. **The transcript.** `yt-dlp` is asked to describe the video, and the caption track it
   names is fetched over http. This is the half no AI package does for you, and it is the
   step that decides whether a video can be summarised at all: no captions, no summary.
2. **The ideas, then the summary.** One pass pulls out what the video actually says; a
   second shapes that into a headline, ten points and five takeaways. Two passes rather
   than one because a transcript is an hour of speech with the shape taken out, and asking
   one prompt to both find what matters *and* phrase it well summarises the opening five
   minutes.
3. **The translation**, for a video that was not in English. The finished summary is
   translated rather than the transcript, so the judgements are made against what was
   actually said. Both versions are kept and shown, the original above.

### Setting it up

`yt-dlp` has to be on `PATH` — on the queue worker, not only on your own machine:

```sh
yt-dlp --version
```

Then point the application at a model. Any provider in `config/ai.php` that generates text
will do; `cohere`, `jina` and `voyageai` do embeddings and reranking and `eleven` does
audio, so naming one of those fails at the first prompt rather than at startup.

```ini
AI_PROVIDER=openai-compatible
OPENAI_COMPATIBLE_URL=https://your-openwebui.example.com/api
OPENAI_COMPATIBLE_API_KEY=sk-…
OPENAI_COMPATIBLE_MODEL=gemma4:e4b
```

Two things that are easy to get wrong here:

- **The url is the api root, not the host.** OpenWebUI serves its OpenAI-compatible
  endpoints under `/api`, so the value ends there. Without it every prompt gets a 404.
- **The model is required.** Hosted providers know their own model names and this driver
  cannot, so leaving it empty fails with `requires a default text model`.

The `openai-compatible` driver posts to `{url}/chat/completions` and never touches the
Responses API, which belongs to the separate `openai` driver — so an endpoint with the
Responses API disabled works here.

Check the lot before summarising anything:

```sh
php artisan ai:check
```

It reports the resolved provider, url and model, lists what the endpoint offers, sends one
tiny prompt, and then proves structured output works. That last check is the one worth
having: both summarising agents ask for a json schema, and whether an OpenAI-compatible
gateway passes that through to the model underneath is the likeliest thing to be wrong
about a self-hosted setup that otherwise answers perfectly well. The key is never printed.

Summaries are produced by a queued job on its own connection, so a worker has to be
running:

```sh
php artisan queue:work summaries
```

### Suggested context window

There is no chunking and no length cap: the whole transcript goes into one prompt. So the
context window is what decides the longest video this can summarise, and a window too small
for one means the provider either refuses the prompt or quietly truncates it — and a
summary of the first half of a video looks exactly like a summary.

Speech runs at roughly **200 tokens per minute** of video. Measured rather than guessed: a
64-minute lecture transcribes to 46,718 characters, about 11,700 tokens, or 183 per minute.
Add ~1,500 for the instructions and the ideas the first pass writes out.

| Context window | Longest video |
| --- | --- |
| 8k | about half an hour |
| 16k | about an hour |
| 32k | about two and a half hours |
| 64k | about five hours |
| 128k and up | anything you are likely to paste |

**32k is the sensible minimum and 64k is comfortable.** Below 16k this is only useful for
short videos. The other passes are nowhere near the limit — they read the ideas rather than
the transcript, which is a thousand tokens or so — so the first pass is the only one worth
sizing for.

### Retention

Summaries are deleted after **seven days**, along with the transcripts stored beside them:

```ini
SUMMARY_RETENTION_DAYS=7
```

`summaries:prune` does it, scheduled daily at 03:00, and it is not a maintenance convenience.
A transcript is a recording of somebody speaking, written down and kept by us, and nobody
asked them; keeping it indefinitely because deleting it was never anybody's job is the
storage limitation the AVG is about. So the window is short by default and it runs on a
schedule rather than waiting to be remembered.

The window is measured from when a video was **last asked for**, not from when its row was
created — so it deletes what nobody has wanted in a week, and asking for a video again
renews it. Deleting a summary is less destructive than it sounds: asking for the same video
again produces a new one, at the cost of making it again.

`SUMMARY_RETENTION_DAYS=0` switches it off and keeps everything. The command says so on
every run rather than silently deleting nothing, since a nightly job that removes nothing
looks identical to one that is working.

Transcripts are stored, so you can measure your own corpus rather than trusting the table:

```sh
php artisan tinker --execute 'App\Models\Summary::query()
    ->whereNotNull("transcript")
    ->get(["video_id", "transcript"])
    ->each(fn ($s) => print($s->video_id." ~".intdiv(strlen($s->transcript), 4)." tokens\n"));'
```

A local model is often slower than a hosted one rather than smaller, and the AI SDK's own
default timeout is 60 seconds. `SUMMARY_MODEL_TIMEOUT` is the budget for one prompt and
defaults to 600.

## Test coverage

`phpunit.xml` scopes coverage to `app/`, and the suite is kept at 100%:

```sh
php artisan test --coverage --min=100
```

CI does not check this. `composer ci:check` runs the suite without coverage and the
workflow installs PHP with `coverage: none`, so the 100% is a convention this README
records rather than a gate. Run it yourself before opening a pull request.

That command needs a coverage driver. If your PHP has neither pcov nor Xdebug, or is a
static build you cannot add extensions to, measure it in a throwaway container instead:

```sh
docker build -t ytsummarise-coverage - <<'EOF'
FROM php:8.5-cli
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions mbstring pdo_sqlite pcov
EOF

docker run --rm -v "$PWD":/app -w /app ytsummarise-coverage \
    php vendor/bin/pest --coverage --min=100
```

`vendor/` is pure PHP and the tests run against in-memory SQLite, so the mounted
checkout works as it is; only the extensions differ. Nothing is written back into the
repository, and `docker rmi ytsummarise-coverage` removes the image again.

## TODO
- add redis to manage queues, session
- add horizon
- associate summaries with users
- add list of requested videos with the status
- notify by email/ntfy when ready (if user wants to, toggle in profile)

