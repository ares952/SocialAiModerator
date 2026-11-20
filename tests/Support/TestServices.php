<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ai\Classifier;
use App\Ai\OpenAiClient;
use App\Audit\AuditLog;
use App\Core\Encryptor;
use App\Evaluation\EvaluationRunner;
use App\Evaluation\EvaluationSamples;
use App\Knowledge\KnowledgeBase;
use App\Moderation\Mode;
use App\Moderation\Policies;
use App\Moderation\PolicyEngine;
use Nette\Database\Explorer;
use Nette\StaticClass;


/**
 * Services wired for tests the same way as in config/services.neon, with fake boundaries.
 */
final class TestServices
{
	use StaticClass;

	public static function knowledge(Explorer $database): KnowledgeBase
	{
		return new KnowledgeBase($database, new AuditLog($database), ['cs', 'en', 'es'], 'Europe/Prague');
	}


	public static function classifier(Explorer $database, OpenAiClient $client, string $model = 'test-model'): Classifier
	{
		return new Classifier($client, self::knowledge($database), $model, 'omni-moderation-latest');
	}


	public static function policies(Explorer $database): Policies
	{
		return new Policies($database, new AuditLog($database), Mode::Observe);
	}


	public static function evaluationRunner(Explorer $database, Encryptor $encryptor, OpenAiClient $client): EvaluationRunner
	{
		return new EvaluationRunner(
			$database,
			new EvaluationSamples($database, $encryptor, new AuditLog($database)),
			self::classifier($database, $client),
			self::policies($database),
			new PolicyEngine,
		);
	}
}
