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
}
