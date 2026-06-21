<?php

namespace Sendportal\Base\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SendBulkEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email_service_id' => [
                'required',
                'integer',
                'exists:sendportal_email_services,id',
            ],
            'from_name' => [
                'required',
                'max:255',
            ],
            'from_email' => [
                'required',
                'max:255',
                'email',
            ],
            'emails' => [
                'required',
                'array',
                'max:100',
            ],
            'emails.*.row' => [
                'required',
                'integer',
            ],
            'emails.*.recipient_email' => [
                'required',
                'email',
            ],
            'emails.*.subject' => [
                'required',
                'max:255',
            ],
            'emails.*.content' => [
                'required',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'emails.required' => __('At least one email is required.'),
            'emails.max' => __('A maximum of 100 emails may be sent per request.'),
            'emails.*.row.required' => __('A row correlation id is required for each email.'),
            'emails.*.recipient_email.required' => __('A recipient email address is required.'),
        ];
    }
}
