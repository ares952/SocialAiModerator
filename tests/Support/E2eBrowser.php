<?php

declare(strict_types=1);

namespace Tests\Support;

use Tester\Environment;


/**
 * Minimal browser for tests/E2e: keeps cookies, never follows redirects, sends the headers of a
 * same-origin navigation (Nette refuses cross-origin form submissions and signals).
 */
final class E2eBrowser
{
	/** @var array<string, string> */
	private array $cookies = [];


	public function __construct(
		private readonly string $baseUrl,
	) {
	}


	/**
	 * Skips the test outside the e2e stack (bin/e2e), so it never touches an instance's data.
	 */
	public static function create(): self
	{
		$baseUrl = getenv('E2E_BASE_URL');
		if (getenv('E2E') !== '1' || !is_string($baseUrl) || $baseUrl === '') {
			Environment::skip('End-to-end tests run only in the e2e stack (bin/e2e).');
		}

		return new self((string) $baseUrl);
	}


	/**
	 * @param array<string, string> $headers
	 */
	public function get(string $path, array $headers = []): E2eResponse
	{
		return $this->request('GET', $path, null, $headers);
	}


	/**
	 * @param array<string, string|list<string>> $fields
	 * @param array<string, string> $headers
	 */
	public function post(string $path, array $fields, array $headers = []): E2eResponse
	{
		return $this->request('POST', $path, $fields, $headers);
	}


	/**
	 * Loads the page, takes the CSRF token of the Nette form and submits it.
	 *
	 * @param array<string, string|list<string>> $fields
	 */
	public function submit(string $path, string $form, array $fields): E2eResponse
	{
		$page = $this->get($path);
		$token = preg_match('~id="frm-' . preg_quote($form, '~') . '".*?name="_token_" value="([^"]*)"~s', $page->body, $match) === 1
			? html_entity_decode($match[1])
			: throw new \LogicException("Form $form not found on $path (HTTP $page->status).");

		return $this->post($path, $fields + [
			'_token_' => $token,
			'_do' => "$form-submit",
		]);
	}


	public function signIn(string $email, string $password): E2eResponse
	{
		return $this->submit('/sign/in', 'signInForm', [
			'email' => $email,
			'password' => $password,
			'send' => '1',
		]);
	}


	/**
	 * @param array<string, string|list<string>>|null $fields
	 * @param array<string, string> $headers
	 */
	private function request(string $method, string $path, ?array $fields, array $headers): E2eResponse
	{
		assert($method !== '');
		$headers += [
			'Sec-Fetch-Site' => 'same-origin',
		];
		if ($this->cookies !== []) {
			$headers['Cookie'] = implode('; ', array_map(
				fn(string $name, string $value): string => "$name=$value",
				array_keys($this->cookies),
				$this->cookies,
			));
		}

		$received = [];
		$curl = curl_init($this->baseUrl . $path);
		curl_setopt_array($curl, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTPHEADER => array_map(
				fn(string $name, string $value): string => "$name: $value",
				array_keys($headers),
				$headers,
			),
			CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$received): int {
				if (str_contains($line, ':')) {
					[$name, $value] = explode(':', $line, 2);
					$received[strtolower(trim($name))][] = trim($value);
				}

				return strlen($line);
			},
		]);
		if ($fields !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, self::encode($fields));
		}

		$body = curl_exec($curl);
		if (!is_string($body)) {
			throw new \RuntimeException("$method $path failed: " . curl_error($curl));
		}

		foreach ($received['set-cookie'] ?? [] as $cookie) {
			[$pair] = explode(';', $cookie, 2);
			[$name, $value] = explode('=', $pair, 2) + [1 => ''];
			$this->cookies[trim($name)] = trim($value);
		}

		return new E2eResponse(curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $received, $body);
	}


	/**
	 * @param array<string, string|list<string>> $fields
	 */
	private static function encode(array $fields): string
	{
		$pairs = [];
		foreach ($fields as $name => $value) {
			foreach ((array) $value as $item) {
				$pairs[] = rawurlencode(is_array($value) ? "{$name}[]" : $name) . '=' . rawurlencode($item);
			}
		}

		return implode('&', $pairs);
	}
}
