<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Account\TokenProvider;
use App\Ai\Classifier;
use App\Ai\OpenAiClient;
use App\Ai\ReplyGenerator;
use App\Audit\AuditLog;
use App\Comment\Comments;
use App\Conversation\Conversations;
use App\Conversation\EchoGuard;
use App\Core\Encryptor;
use App\Core\Logger;
use App\Evaluation\EvaluationRunner;
use App\Evaluation\EvaluationSamples;
use App\Ingest\EventProcessor;
use App\Ingest\EventStore;
use App\Knowledge\KnowledgeBase;
use App\Meta\EventNormalizer;
use App\Meta\MetaClient;
use App\Moderation\AutomationSwitch;
use App\Moderation\Decisions;
use App\Moderation\EventDecider;
use App\Moderation\Mode;
use App\Moderation\Policies;
use App\Moderation\PolicyEngine;
use App\Outbox\ActionDispatcher;
use App\Outbox\Actions;
use App\Outbox\ReplyDrafter;
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
			self::conversations($database, $encryptor),
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
			self::conversations($database, $encryptor),
			self::actions($database, $encryptor, $queue),
		);
	}


	public static function actions(Explorer $database, Encryptor $encryptor, JobQueue $queue): Actions
	{
		return new Actions($database, $queue, self::policies($database), new AuditLog($database), $encryptor);
	}


	public static function dispatcher(Explorer $database, Encryptor $encryptor, MetaClient $meta, Logger $logger): ActionDispatcher
	{
		return new ActionDispatcher(
			pauseRetrySeconds: 60,
			database: $database,
			meta: $meta,
			tokens: new TokenProvider($database, $encryptor),
			policies: self::policies($database),
			engine: new PolicyEngine,
			switch: new AutomationSwitch($database, new AuditLog($database)),
			comments: new Comments($database, $encryptor),
			encryptor: $encryptor,
			logger: $logger,
		);
	}


	public static function drafter(Explorer $database, Encryptor $encryptor, JobQueue $queue, OpenAiClient $client, Logger $logger, string $model = 'reply-model'): ReplyDrafter
	{
		return new ReplyDrafter(
			$database,
			self::actions($database, $encryptor, $queue),
			new Comments($database, $encryptor),
			self::conversations($database, $encryptor),
			self::knowledge($database),
			new ReplyGenerator($client, $model),
			$logger,
		);
	}


	public static function conversations(Explorer $database, Encryptor $encryptor): Conversations
	{
		return new Conversations($database, $encryptor, new AuditLog($database), new EchoGuard($database, '1000000000000099'));
	}
}
