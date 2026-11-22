<?php

declare(strict_types=1);

namespace App\Meta;


/**
 * Read boundary to the Meta Graph API (docs/meta-checklist.md, Graph API): token checks now, reconciliation
 * reads later. It never writes; writes go only through MetaClient and the ActionDispatcher.
 * Production: HttpGraphReader; tests: Tests\Fakes\FakeGraphReader.
 */
interface GraphReader
{
	/**
	 * @throws MetaFailure the check itself failed (network, rate limit, app credentials)
	 */
	public function debugToken(#[\SensitiveParameter] string $token): TokenInfo;
}
