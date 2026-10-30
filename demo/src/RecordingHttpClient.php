<?php

namespace ToolGuardDemo;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Keeps the last request and response of the model calls, so that a failed
 * call shows what the provider really answered (an error message can be lost
 * on the way to a "Bad Request").
 */
final class RecordingHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    public ?string $lastRequest = null;
    public ?ResponseInterface $lastResponse = null;

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->lastRequest = $method.' '.$url."\n".(\is_string($options['body'] ?? null) ? $options['body'] : json_encode($options['json'] ?? null, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        // buffered, so the body can be read again after the platform has read it
        return $this->lastResponse = $this->client->request($method, $url, ['buffer' => true] + $options);
    }

    /** The provider's raw answer to the last call when it failed, else null. */
    public function lastError(): ?string
    {
        try {
            if (null === $this->lastResponse || $this->lastResponse->getStatusCode() < 400) {
                return null;
            }

            return $this->lastResponse->getStatusCode().' '.mb_substr($this->lastResponse->getContent(false), 0, 800);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
