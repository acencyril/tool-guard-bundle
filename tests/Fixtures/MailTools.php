<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Tests\Fixtures;

use Acencyril\ToolGuardBundle\Attribute\AllowedValues;

/** A mail tool: inside the company it goes; anywhere else, a human approves or it is refused. */
final class MailTools
{
    #[AllowedValues('to', ['*@mycompany.com'])]
    public function send(string|array $to, string $body): string
    {
        return 'sent';
    }

    #[AllowedValues('to', ['*@mycompany.com', 'partner@supplier.fr'], otherwise: AllowedValues::DENY)]
    public function forward(string|array $to): string
    {
        return 'forwarded';
    }
}
