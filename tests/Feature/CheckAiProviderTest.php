<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\StructuredAnonymousAgent;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * An endpoint that lists the models phpunit.xml pins the configuration to.
 *
 * @param  array<int, string>  $models
 */
function fakeModelList(array $models = ['test-model']): void
{
    Http::fake([
        'ai.test/api/models' => Http::response([
            'data' => array_map(fn (string $model): array => ['id' => $model], $models),
        ]),
    ]);
}

/**
 * Everything the command printed, as one string.
 *
 * For the assertions that need to see more than one thing in a single rendered component, which
 * expectsOutputToContain cannot: it matches each expectation against one write, and a bullet list
 * is written whole.
 */
function commandOutput(): string
{
    $output = new BufferedOutput;

    app(Kernel::class)->call('ai:check', [], $output);

    return $output->fetch();
}

/**
 * A provider that answers everything asked of it.
 */
function fakeWorkingProvider(): void
{
    fakeModelList();

    AnonymousAgent::fake(fn (): string => 'ok');
    StructuredAnonymousAgent::fake(fn (): array => ['ok' => true]);
}

test('a working provider passes every check', function (): void {
    fakeWorkingProvider();

    $this->artisan('ai:check')
        ->assertSuccessful()
        ->expectsOutputToContain('The endpoint offers 1 model.')
        ->expectsOutputToContain('The model answered: ok')
        ->expectsOutputToContain('Structured output works.');
});

/*
 * The names and not only the count, because the name is what somebody has come here to find:
 * the next thing they do with this output is paste one into OPENAI_COMPATIBLE_MODEL.
 *
 * Read off the captured output rather than asserted with expectsOutputToContain, which cannot
 * see more than one of these. That helper registers each expectation against a single write, and
 * the whole list is rendered in one - so the second name would fail however plainly it is
 * printed. Worth knowing before writing another test that expects two things from one component.
 */
test('the models the endpoint offers are named', function (): void {
    fakeModelList(['gemma4:e4b', 'nomic-embed-text:latest']);

    AnonymousAgent::fake(fn (): string => 'ok');
    StructuredAnonymousAgent::fake(fn (): array => ['ok' => true]);

    expect(commandOutput())
        ->toContain('gemma4:e4b')
        ->toContain('nomic-embed-text:latest');
});

/*
 * A hosted provider lists hundreds, and a wall of them buries the checks below.
 */
test('a long list of models is truncated rather than printed whole', function (): void {
    fakeModelList([...array_map(fn (int $n): string => 'model-'.$n, range(1, 20)), 'test-model']);

    AnonymousAgent::fake(fn (): string => 'ok');
    StructuredAnonymousAgent::fake(fn (): array => ['ok' => true]);

    expect(commandOutput())
        ->toContain('model-15')
        ->not->toContain('model-16')
        ->toContain('6 more not shown.');
});

test('the command reports what the application thinks it is talking to', function (): void {
    fakeWorkingProvider();

    $this->artisan('ai:check')
        ->expectsOutputToContain('openai-compatible')
        ->expectsOutputToContain('https://ai.test/api')
        ->expectsOutputToContain('test-model')
        ->assertSuccessful();
});

/*
 * The one value here worth being careful with, and the least interesting to look at. Whether it
 * is set at all is the whole of what this command can usefully say about it.
 */
test('the key is never printed', function (): void {
    fakeWorkingProvider();

    $this->artisan('ai:check')
        ->doesntExpectOutputToContain('test-ai-key')
        ->expectsOutputToContain('set, 11 characters')
        ->assertSuccessful();
});

test('a provider that is not configured at all is reported rather than thrown', function (): void {
    config()->set('ai.default', 'nonexistent');

    $this->artisan('ai:check')
        ->expectsOutputToContain('AI_PROVIDER is [nonexistent]')
        ->assertFailed();
});

/*
 * The mistake this command mostly exists for. OpenWebUI serves its OpenAI-compatible endpoints
 * under /api, and a url without it answers 404 to everything - which from inside a queued job
 * looks exactly like a model that would not answer.
 */
test('an endpoint that refuses the model list fails the check', function (): void {
    Http::fake(['ai.test/api/models' => Http::response(status: 404)]);

    $this->artisan('ai:check')
        ->expectsOutputToContain('The endpoint answered 404')
        ->assertFailed();
});

test('an endpoint that cannot be reached at all fails the check', function (): void {
    Http::fake(['ai.test/api/models' => fn () => throw new ConnectionException('Could not resolve host')]);

    $this->artisan('ai:check')
        ->expectsOutputToContain('Could not reach the endpoint')
        ->assertFailed();
});

/*
 * A warning rather than an error: an endpoint is free to serve a model it does not advertise,
 * and refusing to continue over that would be the command being surer than it can be.
 */
test('a model the endpoint does not list is a warning rather than a failure', function (): void {
    fakeModelList(['some-other-model']);

    AnonymousAgent::fake(fn (): string => 'ok');
    StructuredAnonymousAgent::fake(fn (): array => ['ok' => true]);

    $this->artisan('ai:check')
        ->expectsOutputToContain('It does not list [test-model]')
        ->assertSuccessful();
});

/*
 * The most common misconfiguration there is, and the command used to report it as the driver
 * not offering a model list - which is the wrong cause for the first thing anybody has to fix.
 */
test('a provider with no url says so rather than blaming the driver', function (): void {
    config()->set('ai.providers.openai-compatible.url');

    $this->artisan('ai:check')
        ->expectsOutputToContain('has no url configured')
        ->doesntExpectOutputToContain('does not offer them')
        ->assertFailed();
});

test('a driver with no model list is skipped rather than failed', function (): void {
    config()->set('ai.default', 'anthropic');

    AnonymousAgent::fake(fn (): string => 'ok');
    StructuredAnonymousAgent::fake(fn (): array => ['ok' => true]);

    $this->artisan('ai:check')
        ->expectsOutputToContain('this driver does not offer them')
        ->assertSuccessful();
});

test('a model that will not answer fails the check', function (): void {
    fakeModelList();

    AnonymousAgent::fake(fn () => throw new RuntimeException('402 Payment Required'));

    $this->artisan('ai:check')
        ->expectsOutputToContain('The model would not answer: 402 Payment Required')
        ->assertFailed();
});

/*
 * The check worth having. Both summarising agents declare structured output, so an endpoint that
 * quietly drops a json schema produces prose where the page expects sections - which surfaces
 * much later as a summary that failed for no visible reason.
 */
test('an endpoint that ignores the schema fails the check', function (): void {
    fakeModelList();

    AnonymousAgent::fake(fn (): string => 'ok');
    StructuredAnonymousAgent::fake(fn (): array => ['some prose instead' => true]);

    $this->artisan('ai:check')
        ->expectsOutputToContain('without the field the schema asked for')
        ->assertFailed();
});

test('an endpoint that refuses structured output altogether fails the check', function (): void {
    fakeModelList();

    AnonymousAgent::fake(fn (): string => 'ok');
    StructuredAnonymousAgent::fake(fn () => throw new RuntimeException('response_format is not supported'));

    $this->artisan('ai:check')
        ->expectsOutputToContain('Structured output did not work: response_format is not supported')
        ->assertFailed();
});

/*
 * Cheapest first, and stopping at the first wrong answer. Asking a model to answer when the
 * endpoint has already refused to say what models it has is a second failure explaining the
 * same thing, and the first one is the useful one.
 */
test('the checks stop at the first one that answers wrongly', function (): void {
    Http::fake(['ai.test/api/models' => Http::response(status: 401)]);

    /*
     * Not faked, so reaching one would send a real request and trip the suite's guard. That is
     * the assertion: the command never got that far.
     */
    $this->artisan('ai:check')->assertFailed();
});
