<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FirstUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Creates the one account this application needs while authentication is disabled.
 *
 * Both actions refuse the request unless authentication is off and the users table is
 * empty, so this page does not exist on a normal Authentik installation and stops
 * existing the moment somebody has been created.
 */
class FirstUserController extends Controller
{
    /**
     * Show the form that creates the first user.
     */
    public function create(): Response
    {
        $this->abortUnlessSetupIsPending();

        return Inertia::render('auth/first-user');
    }

    /**
     * Create the first user and sign them in.
     */
    public function store(FirstUserRequest $request): RedirectResponse
    {
        $this->abortUnlessSetupIsPending();

        $user = User::create($request->validated());

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('home');
    }

    /**
     * Refuse the request unless the application still needs its first user.
     */
    private function abortUnlessSetupIsPending(): void
    {
        abort_if(config('auth.enabled') || User::exists(), 404);
    }
}
