<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Inertia\Middleware;
use Override;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * The translation groups the pages read.
     *
     * A group missing from here reaches the frontend as nothing at all, and every key from it
     * renders as the key itself, which is deliberately visible rather than blank. Adding a
     * lang/en file is therefore two steps, and this is the second.
     *
     * Shared on every response rather than through Inertia::once(), which would send them one
     * time and let the client remember them. Two small arrays are worth less than the class of
     * bug where a page renders raw keys because it was reached in a way that never carried
     * them, and translate() failing loudly only helps if the strings are reliably there.
     *
     * @var list<string>
     */
    private const array TRANSLATED_GROUPS = ['app', 'summaries'];

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    #[Override]
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'enabled' => config('auth.enabled'),
            ],
            'lang' => $this->translations(),
        ];
    }

    /**
     * The shared groups, as the nested arrays the frontend walks with a dotted key.
     *
     * Handed over whole rather than flattened, because that is the shape the lang files are
     * already in and the shape resources/js/lib/lang.ts expects.
     *
     * @return array<string, mixed>
     */
    private function translations(): array
    {
        $groups = [];

        foreach (self::TRANSLATED_GROUPS as $group) {
            /*
             * Cast rather than checked. A group with no file behind it comes back from Lang as
             * its own name, and wrapping that is what makes the intended failure visible: the
             * page renders raw keys instead of blank space. Nothing to branch on.
             */
            $groups[$group] = (array) Lang::get($group);
        }

        return $groups;
    }
}
