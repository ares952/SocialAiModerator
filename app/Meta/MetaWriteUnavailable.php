<?php

declare(strict_types=1);

namespace App\Meta;


/**
 * The application cannot write to Meta yet; nothing was sent.
 */
final class MetaWriteUnavailable extends \RuntimeException
{
}
