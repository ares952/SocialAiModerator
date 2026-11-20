<?php

declare(strict_types=1);

namespace App\Ai;


/**
 * Model output that does not match the expected structure or value ranges.
 */
final class InvalidModelOutput extends \RuntimeException
{
}
