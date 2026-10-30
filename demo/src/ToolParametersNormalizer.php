<?php

namespace ToolGuardDemo;

use Symfony\AI\Platform\Contract\Normalizer\ToolNormalizer;

/**
 * A tool without arguments is sent with an empty parameter schema instead of
 * none: Hugging Face Inference Providers refuse a tool without "parameters"
 * ("Tool ... is missing function parameters"), OpenAI-compatible APIs accept both.
 */
final class ToolParametersNormalizer extends ToolNormalizer
{
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $tool = parent::normalize($data, $format, $context);
        $tool['function']['parameters'] ??= ['type' => 'object', 'properties' => new \stdClass()];

        return $tool;
    }
}
