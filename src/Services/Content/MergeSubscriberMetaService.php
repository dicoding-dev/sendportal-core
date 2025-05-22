<?php

declare(strict_types=1);

namespace Sendportal\Base\Services\Content;

use Sendportal\Base\Models\Subscriber;
use Sendportal\Base\Traits\NormalizeTags;

class MergeSubscriberMetaService
{
    use NormalizeTags;

    private array $tags;

    public function __construct(Subscriber $subscriber)
    {
        $this->tags = $subscriber->meta ?? [];
    }

    public function handle(string $content): string
    {
        return $this->mergeTags($content);
    }

    protected function mergeTags(string $content): string
    {
        return $this->mergeMetaTagsValue(
            $this->compileTags($content)
        );
    }

    protected function compileTags(string $content): string
    {
        foreach (array_keys($this->tags) as $tag) {
            $content = $this->normalizeTags($content, $tag);
        }

        return $content;
    }

    protected function mergeMetaTagsValue(string $content): string
    {
        foreach ($this->tags as $tag => $value) {
            $content = str_ireplace('{{' . $tag . '}}', (string) $value, $content);
        }

        return $content;
    }
}
