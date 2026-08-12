<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Answers "is the model actually wired up", in one command and without summarising anything.
 *
 * Everything else in this application reaches a model from inside a queued job, which is the
 * worst possible place to find out that a url has no /api on the end: the failure arrives as a
 * summary that did not work, a line in the worker log, and no indication whether the problem is
 * the url, the key, the model name or the endpoint's idea of structured output.
 *
 * So this asks the same four questions in order, cheapest first, and stops at the first one that
 * answers wrongly. The AI SDK ships something similar and leaves it commented out of its own
 * service provider, so there is nothing built in to use instead.
 *
 * The last check is the one worth having. Both summarising agents declare structured output, and
 * whether an OpenAI-compatible endpoint passes a json schema through to the model underneath is
 * the single most likely thing to be wrong about a self-hosted setup that otherwise answers
 * perfectly well.
 *
 * Never prints the key. It is the one value here worth being careful with and the least
 * interesting to look at: whether it is set at all is the whole of what this can tell you.
 */
#[Signature('ai:check')]
#[Description('Check that the configured AI provider is reachable and answers usefully')]
class CheckAiProvider extends Command
{
    /**
     * The drivers that answer a GET on /models.
     *
     * Both speak OpenAI's dialect, where listing the models is a documented endpoint. The rest
     * either have no such thing or spell it differently, and asking them is a request that fails
     * for reasons having nothing to do with whether the provider works.
     */
    private const array LISTS_MODELS = ['openai', 'openai-compatible'];

    /**
     * How many model names the command will print.
     *
     * Named rather than counted, because the name is what somebody has come here to find: the
     * next thing they do with this output is paste one of these into OPENAI_COMPATIBLE_MODEL.
     * Capped because a hosted provider lists hundreds and a wall of them buries the checks below.
     */
    private const int MODELS_LISTED = 15;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = config()->string('ai.default');

        /** @var array<string, mixed>|null $provider */
        $provider = config('ai.providers.'.$name);

        if (! is_array($provider)) {
            $this->components->error(sprintf(
                'AI_PROVIDER is [%s], which is not one of the providers in config/ai.php.',
                $name,
            ));

            return self::FAILURE;
        }

        $this->configuration($name, $provider);

        return $this->models($provider) && $this->text() && $this->structured()
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * What the application thinks it is talking to.
     *
     * @param  array<string, mixed>  $provider
     */
    private function configuration(string $name, array $provider): void
    {
        $key = $provider['key'] ?? null;

        $this->components->twoColumnDetail('Provider', $name);
        $this->components->twoColumnDetail('Driver', $this->string($provider, 'driver') ?? 'not set');
        $this->components->twoColumnDetail('URL', $this->string($provider, 'url') ?? 'the provider default');
        $this->components->twoColumnDetail('Model', $this->model($provider) ?? 'the provider default');
        $this->components->twoColumnDetail(
            'Key',
            is_string($key) && $key !== '' ? sprintf('set, %d characters', mb_strlen($key)) : 'not set',
        );

        $this->newLine();
    }

    /**
     * Ask the endpoint what it can do, which is the cheapest way to be sure of the url and key.
     *
     * A 404 here is a url missing its api path - the mistake this whole command exists for - and
     * a 401 is the key. Both are worth telling apart from "the model would not answer", which is
     * what either of them looks like from inside a job.
     *
     * Skipped rather than failed for a driver that has no such endpoint, because not offering
     * one is not a fault.
     *
     * @param  array<string, mixed>  $provider
     */
    private function models(array $provider): bool
    {
        $url = $this->string($provider, 'url');
        $driver = $this->string($provider, 'driver');

        if ($url === null || ! in_array($driver, self::LISTS_MODELS, true)) {
            $this->components->warn('Skipped listing models: this driver does not offer them.');

            return true;
        }

        $key = $provider['key'] ?? null;

        try {
            $response = Http::when(
                is_string($key) && $key !== '',
                fn ($request) => $request->withToken((string) $key),
            )->get(Str::rtrim($url, '/').'/models');
        } catch (Throwable $exception) {
            $this->components->error('Could not reach the endpoint: '.$exception->getMessage());

            return false;
        }

        if (! $response->successful()) {
            $this->components->error(sprintf(
                'The endpoint answered %d when asked for its models.',
                $response->status(),
            ));

            return false;
        }

        $models = $response->json('data.*.id');
        $models = is_array($models) ? array_filter($models, is_string(...)) : [];

        $this->components->info(sprintf('The endpoint offers %d %s.', count($models), Str::plural('model', $models)));

        $this->components->bulletList(array_slice($models, 0, self::MODELS_LISTED));

        if (count($models) > self::MODELS_LISTED) {
            $this->components->warn(sprintf('%d more not shown.', count($models) - self::MODELS_LISTED));
        }

        $configured = $this->model($provider);

        /*
         * A model name the endpoint does not list is worth saying out loud, because it fails
         * later as a refused prompt rather than as anything about configuration. Not an error,
         * though: an endpoint is free to serve a model it does not advertise, and refusing to
         * continue over that would be this command being surer than it can be.
         */
        if ($configured !== null && $models !== [] && ! in_array($configured, $models, true)) {
            $this->components->warn(sprintf('It does not list [%s], which is the configured model.', $configured));
        }

        return true;
    }

    /**
     * One prompt, as small as a prompt can be.
     */
    private function text(): bool
    {
        try {
            $response = agent(instructions: 'Answer with one word and nothing else.')
                ->prompt('Reply with the word: ok');
        } catch (Throwable $exception) {
            $this->components->error('The model would not answer: '.$exception->getMessage());

            return false;
        }

        $this->components->info('The model answered: '.Str::limit(trim($response->text), 60));

        return true;
    }

    /**
     * The check this command is really for.
     *
     * Both summarising agents ask for structured output, so an endpoint that quietly ignores a
     * json schema produces prose where the page expects sections - which surfaces as a summary
     * that failed for no visible reason. Proving it here takes one prompt.
     */
    private function structured(): bool
    {
        try {
            $response = agent(
                instructions: 'Answer using the schema you were given.',
                schema: fn (JsonSchema $schema): array => [
                    'ok' => $schema->boolean()->description('Always true.')->required(),
                ],
            )->prompt('Set ok to true.');
        } catch (Throwable $exception) {
            $this->components->error('Structured output did not work: '.$exception->getMessage());

            return false;
        }

        /*
         * prompt() is typed as returning the base response, since most agents are not
         * structured. The assert is what tells static analysis that passing a schema guarantees
         * one that is - the same shape as the dto() assert in LookupVideo.
         */
        assert($response instanceof StructuredAgentResponse);

        $structured = $response->toArray();

        if (! array_key_exists('ok', $structured)) {
            $this->components->error('The model answered without the field the schema asked for.');

            return false;
        }

        $this->components->info('Structured output works.');

        return true;
    }

    /**
     * The model this provider will use, where its configuration names one.
     *
     * Only the openai-compatible driver requires this, and it is the only one that keeps it
     * here; everything else has a default of its own that the SDK supplies, which is what the
     * null means.
     *
     * @param  array<string, mixed>  $provider
     */
    private function model(array $provider): ?string
    {
        $models = $provider['models'] ?? null;

        if (! is_array($models) || ! is_array($models['text'] ?? null)) {
            return null;
        }

        $model = $models['text']['default'] ?? null;

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * One configuration value, when it is a string worth having.
     *
     * @param  array<string, mixed>  $provider
     */
    private function string(array $provider, string $key): ?string
    {
        $value = $provider[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
