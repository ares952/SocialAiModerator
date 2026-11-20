<?php

declare(strict_types=1);

namespace Tests\Support;


final readonly class E2eResponse
{
	/**
	 * @param array<string, list<string>> $headers lower-case name => values
	 */
	public function __construct(
		public int $status,
		public array $headers,
		public string $body,
	) {
	}


	/**
	 * Path and query of the Location header.
	 */
	public function location(): ?string
	{
		$location = $this->headers['location'][0] ?? null;
		if ($location === null) {
			return null;
		}

		$parts = parse_url($location);

		return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
	}


	public function title(): string
	{
		return preg_match('~<title>\s*(.*?)\s*</title>~s', $this->body, $match) === 1
			? html_entity_decode(preg_replace('~\s+~', ' ', $match[1]) ?? '')
			: '';
	}


	/**
	 * Visible text of alerts and invalid-feedback messages.
	 *
	 * @return list<string>
	 */
	public function messages(): array
	{
		preg_match_all('~class="(?:alert alert-[a-z]+[^"]*|invalid-feedback)"[^>]*>(.*?)(?:<button|</div>)~s', $this->body, $matches);

		return array_values(array_filter(array_map(
			fn(string $html): string => trim(html_entity_decode(strip_tags($html))),
			$matches[1],
		)));
	}


	/**
	 * First href matching the pattern, HTML-decoded.
	 */
	public function link(string $pattern): ?string
	{
		foreach ($this->links() as $href) {
			if (preg_match($pattern, $href) === 1) {
				return $href;
			}
		}

		return null;
	}


	/**
	 * @return list<string>
	 */
	public function links(): array
	{
		preg_match_all('~href="([^"]*)"~', $this->body, $matches);

		return array_map(fn(string $href): string => html_entity_decode($href), $matches[1]);
	}
}
