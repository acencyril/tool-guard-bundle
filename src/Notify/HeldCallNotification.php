<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Notify;

use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Notification\ChatNotificationInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Notification\SmsNotificationInterface;
use Symfony\Component\Notifier\Recipient\RecipientInterface;
use Symfony\Component\Notifier\Recipient\SmsRecipientInterface;

/**
 * A call waiting for a human. Symfony sends only the subject of a plain notification to chat and
 * SMS channels; this one gives them the reason and the arguments too, so the approver decides from
 * the message itself. Email gets the subject and the content as usual.
 */
final class HeldCallNotification extends Notification implements ChatNotificationInterface, SmsNotificationInterface
{
    public function asChatMessage(RecipientInterface $recipient, ?string $transport = null): ?ChatMessage
    {
        return new ChatMessage($this->getEmoji().' '.$this->getSubject()."\n".$this->getContent());
    }

    public function asSmsMessage(SmsRecipientInterface $recipient, ?string $transport = null): ?SmsMessage
    {
        // an SMS stays short: the subject and the reason, the details are on the page
        return new SmsMessage($recipient->getPhone(), $this->getSubject().': '.strtok($this->getContent(), "\n"));
    }
}
