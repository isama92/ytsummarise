<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Name and email are owned by Authentik and refreshed on every sign in; this
 * application never edits them and stores no password of its own.
 */
#[Fillable(['name', 'email'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * There is no password column, and preventAccessingMissingAttributes(), which
     * AppServiceProvider enables in every environment including production, turns
     * the inherited read of $this->password into a MissingAttributeException. An
     * empty string tells SessionGuard::login() there is no password hash to keep
     * in the session.
     */
    public function getAuthPassword(): string
    {
        return '';
    }
}
