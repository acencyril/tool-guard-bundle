<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Notify;

use Acencyril\ToolGuardBundle\Event\ApprovalRequested;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A push to the approvers' phones when a call waits (https://ntfy.sh, or your own ntfy server):
 * no account, no key, the ntfy app subscribed to a topic. The message carries the policy's reason
 * and the raw arguments, never words the model wrote, and a button to the approvals page.
 *
 * The topic is a secret of sorts: anyone who knows it reads the requests. Use a long random one,
 * or your own server with access control (token).
 */
final class NtfyNotifier
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $topicUrl,
        private readonly ?string $approvalsUrl = null,
        #[\SensitiveParameter] private readonly ?string $token = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(ApprovalRequested $event): void
    {
        $a = $event->approval;
        $args = json_encode($a->arguments, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        $headers = [
            'Title' => sprintf('AI action waiting: %s', $a->tool),
            'Priority' => 'high',
            'Tags' => 'lock',
        ];
        if (null !== $this->approvalsUrl && '' !== $this->approvalsUrl) {
            $url = $this->approvalsUrl.'#r-'.$a->id;
            $headers['Click'] = $url;
            $headers['Actions'] = 'view, Review, '.$url;
        }
        if (null !== $this->token && '' !== $this->token) {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }
        try {
            $this->http->request('POST', $this->topicUrl, [
                'headers' => $headers,
                'body' => sprintf("%s\n%s\nasked by %s · #%s", $a->reason, mb_substr((string) $args, 0, 600), $a->requestedBy ?? 'anonymous', $a->id),
                'timeout' => 5,
            ])->getStatusCode();
        } catch (\Throwable $e) {
            // a failed push never blocks the guard: the request waits on the page anyway
            $this->logger?->warning('tool_guard: ntfy push failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }
}
