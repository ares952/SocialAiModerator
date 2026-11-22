<?php

declare(strict_types=1);

namespace App\Outbox;


/**
 * An operator's request on an action cannot be done; the message is a translation key.
 */
final class InvalidAction extends \RuntimeException
{
}
