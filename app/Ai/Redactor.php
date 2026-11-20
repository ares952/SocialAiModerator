<?php

declare(strict_types=1);

namespace App\Ai;


/**
 * Minimizes personal data before customer text goes to OpenAI (specification §8.3): e-mail
 * addresses and phone numbers become placeholders, links keep only their domain (enough to judge
 * spam; the URL is never fetched).
 */
final class Redactor
{
	public static function redact(#[\SensitiveParameter] string $text): string
	{
		$text = preg_replace('~[\w.+-]+@[\w-]+(?:\.[\w-]+)+~u', '[email]', $text) ?? '';
		$text = preg_replace_callback(
			'~\b(?:https?://|www\.)([^\s/?#<>"\']+)[^\s<>"\']*~iu',
			static fn(array $match): string => '[link: ' . mb_strtolower(preg_replace('~^www\.~i', '', $match[1]) ?? '') . ']',
			$text,
		) ?? '';

		return preg_replace('~(?<![\w+])\+?\d[\d \-/().]{7,}\d(?!\w)~u', '[phone]', $text) ?? '';
	}
}
