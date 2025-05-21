<?php

namespace Sendportal\Base\Adapters;

use Sendportal\Base\Interfaces\MailAdapterInterface;

abstract class BaseMailAdapter implements MailAdapterInterface
{
    /** @var array */
    protected $config;

    public function __construct(array $config = [])
    {
        $this->setConfig($config);
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    protected function getListUnsubscribe(): string
    {
        return collect(config('sendportal.list_unsubscribe'))
            ->filter()
            ->map(fn($value, $key) => $key === 'email' ? "<mailto: $value>" : "<$value>")
            ->implode(',');
    }
}
