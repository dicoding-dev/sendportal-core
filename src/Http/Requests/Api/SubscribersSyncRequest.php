<?php

declare(strict_types=1);

namespace Sendportal\Base\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SubscribersSyncRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'subscribers' => ['required', 'array'],
            'subscribers.*.first_name' => ['nullable'],
            'subscribers.*.last_name' => ['nullable'],
            'subscribers.*.email' => ['required', 'email'],
            'subscribers.*.tags' => ['array', 'nullable'],
            'subscribers.*.meta' => ['array', 'nullable'],
            'subscribers.*.unsubscribed_at' => ['nullable', 'date'],
        ];
    }
}
