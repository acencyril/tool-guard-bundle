<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Notify;

use Acencyril\ToolGuardBundle\Event\ApprovalRequested;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;

/**
 * A held call, told to the approvers through Symfony Notifier: whatever channels the app already
 * has in framework.notifier (Slack, Microsoft Teams, Telegram, email, SMS…). The channels come from
 * the configured list, or from framework.notifier.channel_policy for the importance; the recipients
 * are framework.notifier.admin_recipients, as Symfony's own failed-message alerts do.
 *
 * The text is the policy's reason and the raw arguments, never words the model wrote.
 */
final class SymfonyNotifierListener
{
    /** @param list<string> $channels */
    public function __construct(
        private readonly NotifierInterface $notifier,
        private readonly string $importance = Notification::IMPORTANCE_HIGH,
        private readonly array $channels = [],
        private readonly ?string $approvalsUrl = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(ApprovalRequested $event): void
    {
        $a = $event->approval;
        $args = json_encode($a->arguments, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        $content = sprintf("%s\n%s\nasked by %s · #%s", $a->reason, mb_substr((string) $args, 0, 600), $a->requestedBy ?? 'anonymous', $a->id);
        if (null !== $this->approvalsUrl && '' !== $this->approvalsUrl) {
            $content .= "\nReview: ".$this->approvalsUrl.'#r-'.$a->id;
        }
        $notification = (new HeldCallNotification(sprintf('AI action waiting: %s', $a->tool), $this->channels))
            ->content($content)
            ->importance($this->importance)
            ->emoji('🔒');
        $recipients = method_exists($this->notifier, 'getAdminRecipients') ? $this->notifier->getAdminRecipients() : [];

        try {
            $this->notifier->send($notification, ...$recipients);
        } catch (\Throwable $e) {
            // a failed notification never blocks the guard: the request waits on the page anyway
            $this->logger?->warning('tool_guard: notifier failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }
}
