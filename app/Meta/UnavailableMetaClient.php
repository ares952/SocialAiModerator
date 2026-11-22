<?php

declare(strict_types=1);

namespace App\Meta;

use App\Account\AccessToken;


/**
 * Production Meta client until the Graph write endpoints and permissions are confirmed with the
 * owner (AGENTS.md, specification §12.11): every write is refused without a request, so an
 * approved action ends as blocked.
 */
final class UnavailableMetaClient implements MetaClient
{
	public function hideComment(Platform $platform, AccessToken $token, string $commentId, bool $hidden): void
	{
		throw new MetaWriteUnavailable('Meta write API is not connected yet.');
	}


	public function sendMessage(Platform $platform, AccessToken $token, string $recipientId, #[\SensitiveParameter] string $text): string
	{
		throw new MetaWriteUnavailable('Meta write API is not connected yet.');
	}


	public function replyComment(Platform $platform, AccessToken $token, string $commentId, #[\SensitiveParameter] string $text): string
	{
		throw new MetaWriteUnavailable('Meta write API is not connected yet.');
	}
}
