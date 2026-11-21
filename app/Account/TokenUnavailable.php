<?php

declare(strict_types=1);

namespace App\Account;


/**
 * The account has no usable token: missing, invalid, expired or without data access. The caller
 * must not act on the account (fail closed).
 */
final class TokenUnavailable extends \RuntimeException
{
}
