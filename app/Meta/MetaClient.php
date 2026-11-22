<?php

declare(strict_types=1);

namespace App\Meta;

use App\Account\AccessToken;


/**
 * Write boundary to the Meta Graph API. Only App\Outbox\ActionDispatcher may call it, and only for
 * an outbox record it has just re-validated. Production uses UnavailableMetaClient until the
 * Graph endpoints and permissions are confirmed (docs/meta-checklist.md); tests use FakeMetaClient.
 */
interface MetaClient
{
	/**
	 * Hides or unhides a comment of the account.
	 *
	 * @param string $commentId ID with the platform prefix (Platform::externalId())
	 * @throws MetaFailure
	 * @throws MetaWriteUnavailable nothing was sent
	 */
	public function hideComment(Platform $platform, AccessToken $token, string $commentId, bool $hidden): void;
}
