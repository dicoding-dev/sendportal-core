<?php

declare(strict_types=1);

namespace Sendportal\Base\Rules;

use Illuminate\Contracts\Validation\Rule;
use Sendportal\Base\Facades\Sendportal;
use Sendportal\Base\Models\Subscriber;

class CanAccessSubscriber implements Rule
{
    public function passes($attribute, $value): bool
    {
        $workspaceId = Sendportal::currentWorkspaceId();
        $subscriber = Subscriber::query()
            ->where('workspace_id', $workspaceId)
            ->find($value);

        if (! $subscriber) {
            return false;
        }

        return $subscriber->workspace_id == $workspaceId;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'The validation error message.';
    }
}
