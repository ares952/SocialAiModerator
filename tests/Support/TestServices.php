<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ai\Classifier;
use App\Ai\OpenAiClient;
use App\Audit\AuditLog;
use App\Comment\Comments;
use App\Conversation\Conversations;
use App\Core\Encryptor;
use App\Evaluation\EvaluationRunner;
use App\Evaluation\EvaluationSamples;
use App\Ingest\EventProcessor;
use App\Ingest\EventStore;
use App\Knowledge\KnowledgeBase;
use App\Meta\EventNormalizer;
use App\Moderation\AutomationSwitch;
use App\Moderation\Decisions;
use App\Moderation\EventDecider;
use App\Moderation\Mode;
use App\Moderation\Policies;
use App\Moderation\PolicyEngine;
use App\Queue\JobQueue;
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


	public static function eventStore(Explorer $database, Encryptor $encryptor, JobQueue $queue): EventStore
	{
		return new EventStore($database, $encryptor, $queue, new EventNormalizer);
	}


	public static function eventProcessor(Explorer $database, Encryptor $encryptor, JobQueue $queue): EventProcessor
	{
		return new EventProcessor(
			$database,
			$encryptor,
			new EventNormalizer,
			$queue,
			new Comments($database, $encryptor),
			new Conversations($database, $encryptor),
		);
	}


	public static function eventDecider(Explorer $database, Encryptor $encryptor, JobQueue $queue, OpenAiClient $client): EventDecider
	{
		return new EventDecider(
			$database,
			self::eventStore($database, $encryptor, $queue),
			self::classifier($database, $client),
			self::policies($database),
			new PolicyEngine,
			new AutomationSwitch($database, new AuditLog($database)),
			new Decisions($database, $encryptor),
			$queue,
			new Comments($database, $encryptor),
			new Conversations($database, $encryptor),
		);
	}
}
