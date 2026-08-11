---
paths:
  - 'resources/js/actions/**'
---

# Actions

## wayfinder:generate needs --with-form to match the build
vite.config.ts configures the wayfinder plugin with formVariants: true, so anything generated during a build or dev run carries the .form() helpers the pages spread into <Form>. Running `php artisan wayfinder:generate` by hand does not read that config and silently omits them, and the resulting type error looks like the controller action is missing rather than the flag.

Regenerate by hand with `php artisan wayfinder:generate --with-form`, or just let vite do it.
