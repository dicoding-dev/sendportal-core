<?php

namespace Sendportal\Base\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SendEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'recipient_email' => [
                'required',
                'email',
            ],
            'subject' => [
                'required',
                'max:255',
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
            'email_service_id' => [
                'required',
                'integer',
                'exists:sendportal_email_services,id',
            ],
            'content' => [
                'required',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_email.required' => __('A recipient email address is required.'),
        ];
    }
}
