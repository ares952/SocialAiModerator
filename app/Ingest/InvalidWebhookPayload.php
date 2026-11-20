<?php

declare(strict_types=1);

namespace App\Ingest;


/**
 * A signed webhook body with an unexpected structure. Messages never contain customer content.
 */
final class InvalidWebhookPayload extends \RuntimeException
{
}
