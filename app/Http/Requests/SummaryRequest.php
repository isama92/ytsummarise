<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Summary;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Stringable;

class SummaryRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The client pulls the id out of whatever the user pasted and sends only that, so no
     * urls ever arrive here. Nothing about that is enforceable from the browser though,
     * which is why the shape is checked again on this side.
     *
     * @return array<string, array<int, ValidationRule|Stringable|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'video_id' => ['required', 'string', 'regex:'.Summary::VIDEO_ID_PATTERN],
        ];
    }
}
