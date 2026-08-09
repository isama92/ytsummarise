<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Stringable;

class FirstUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The unique rule is near enough unreachable, because the controller refuses the
     * request once any user exists. It is here for the race between two submissions
     * arriving at an empty table at the same time.
     *
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
        ];
    }
}
