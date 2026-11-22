<?php

declare(strict_types=1);

namespace App\Meta;


enum Platform: string
{
	case Facebook = 'facebook';
	case Instagram = 'instagram';


	/**
	 * Platform of a webhook by its `object` field; null for objects the application does not handle.
	 */
	public static function fromWebhookObject(string $object): ?self
	{
		return match ($object) {
			'page' => self::Facebook,
			'instagram' => self::Instagram,
			default => null,
		};
	}


	/**
	 * Facebook and Instagram IDs come from separate ID spaces, so they are always stored prefixed.
	 */
	public function externalId(string $id): string
	{
		$prefix = match ($this) {
			self::Facebook => 'fb:',
			self::Instagram => 'ig:',
		};

		return $prefix . $id;
	}


	/**
	 * Permissions the account's token needs for the features of v1 (docs/meta-checklist.md, Graph API; Facebook
	 * Login with a Page token for the Page, a system user token for Instagram).
	 *
	 * @return list<string>
	 */
	public function requiredScopes(): array
	{
		return match ($this) {
			self::Facebook => [
				'pages_manage_engagement',
				'pages_manage_metadata',
				'pages_messaging',
				'pages_read_engagement',
				'pages_read_user_content',
			],
			self::Instagram => [
				'instagram_basic',
				'instagram_manage_comments',
				'instagram_manage_messages',
				'pages_manage_metadata',
				'pages_read_engagement',
			],
		};
	}
}
