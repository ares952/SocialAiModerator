<?php

declare(strict_types=1);

namespace App\Moderation;


/**
 * Deterministic checks of the customer text that do not depend on the AI (specification §5.1,
 * step 2): links to blocked domains and too many links. Links are only parsed, never fetched.
 *
 * A link is an address with `http(s)://` or `www.`, or a bare host name such as `spam.example`.
 * Repeated messages and own echo need normalized events (author, conversation) and follow with
 * the EventNormalizer.
 */
final class ContentRules
{
	private const LinkPattern = '~(?:https?://|www\.)(?<host>[\p{L}\p{N}.-]+)'
		. '|(?<![\w@.-])(?<bare>(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,24})(?![\w-])~iu';


	/**
	 * @return list<RuleHit>
	 */
	public static function hits(#[\SensitiveParameter] string $text, PolicySettings $settings): array
	{
		$hosts = self::hosts($text);
		$hits = [];
		if (array_any($hosts, fn(string $host): bool => self::isBlocked($host, $settings->blockedDomains))) {
			$hits[] = RuleHit::BlockedDomain;
		}
		if ($settings->maxLinks !== null && count($hosts) > $settings->maxLinks) {
			$hits[] = RuleHit::TooManyLinks;
		}

		return $hits;
	}


	/**
	 * @return list<string> lower-case host of every link, in order of appearance
	 */
	private static function hosts(#[\SensitiveParameter] string $text): array
	{
		preg_match_all(self::LinkPattern, $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

		$hosts = [];
		foreach ($matches as $match) {
			$hosts[] = mb_strtolower(trim((string) ($match['host'] ?? $match['bare']), '.-'));
		}

		return $hosts;
	}


	/**
	 * A blocked domain also blocks its subdomains.
	 *
	 * @param list<string> $domains
	 */
	private static function isBlocked(string $host, array $domains): bool
	{
		return array_any(
			$domains,
			fn(string $domain): bool => $host === $domain || str_ends_with($host, ".$domain"),
		);
	}
}
