# ytsummarise

A simple app where you paste a YouTube video and it will give you a summary using a hardcoded AI model.

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

