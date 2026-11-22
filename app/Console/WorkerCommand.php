<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Correlation;
use App\Core\Logger;
use App\Evaluation\EvaluationRunner;
use App\Ingest\EventProcessor;
use App\Moderation\EventDecider;
use App\Outbox\ActionDispatcher;
use App\Outbox\ReplyDrafter;
use App\Queue\Job;
use App\Queue\JobQueue;
use App\Queue\JobStatus;
use App\Queue\JobType;
use App\Queue\Postponed;
use App\Retention\Retention;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Long-running background worker (the `worker` container): reserves jobs from the DB queue one
 * by one and applies the retention periods every `retentionSeconds`. Stops gracefully on
 * SIGTERM/SIGINT after the current job.
 */
#[AsCommand(name: 'worker:run', description: 'Runs the background worker until SIGTERM or SIGINT')]
final class WorkerCommand extends Command
{
	private bool $running = true;
	private ?int $lastRetention = null;


	public function __construct(
		private readonly string $heartbeatFile,
		private readonly int $idleSeconds,
		private readonly int $retentionSeconds,
		private readonly JobQueue $queue,
		private readonly EventProcessor $events,
		private readonly EventDecider $decider,
		private readonly EvaluationRunner $evaluation,
		private readonly ActionDispatcher $dispatcher,
		private readonly ReplyDrafter $drafter,
		private readonly Retention $retention,
		private readonly Correlation $correlation,
		private readonly Logger $logger,
	) {
		parent::__construct();
	}


	protected function configure(): void
	{
		$this->addOption('until-empty', null, InputOption::VALUE_NONE, 'Stop when no job is available instead of waiting');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		pcntl_async_signals(true);
		pcntl_signal(SIGTERM, $this->stop(...));
		pcntl_signal(SIGINT, $this->stop(...));

		$worker = gethostname() . ':' . getmypid();
		$this->logger->info('Worker started', [
			'worker' => $worker,
		]);

		while ($this->running) {
			touch($this->heartbeatFile);
			$this->applyRetention();
			$job = $this->queue->reserve($worker);
			if ($job !== null) {
				$this->process($job);
			} elseif ($input->getOption('until-empty') === true) {
				break;
			} else {
				sleep($this->idleSeconds);
			}
		}

		$this->logger->info('Worker stopped', [
			'worker' => $worker,
		]);

		return self::SUCCESS;
	}


	private function process(Job $job): void
	{
		$this->correlation->renew();
		$context = [
			'job_id' => $job->id,
			'job_type' => $job->type->value,
			'attempt' => $job->attempts,
		];

		$context += match ($job->type) {
			JobType::ProcessEvent, JobType::DecideEvent => [
				'event_id' => $job->subjectId,
			],
			JobType::EvaluateSample => [
				'evaluation_result_id' => $job->subjectId,
			],
			JobType::SendAction => [
				'outbox_id' => $job->subjectId,
			],
			JobType::DraftReply => [
				'action_id' => $job->subjectId,
			],
		};

		try {
			match ($job->type) {
				JobType::ProcessEvent => $this->events->process($job->subjectId),
				JobType::DecideEvent => $this->decider->decide($job->subjectId, $this->queue->isLastAttempt($job)),
				JobType::EvaluateSample => $this->evaluation->evaluate($job->subjectId, $this->queue->isLastAttempt($job)),
				JobType::SendAction => $this->dispatcher->send($job->subjectId, $this->queue->isLastAttempt($job)),
				JobType::DraftReply => $this->drafter->draft($job->subjectId, $this->queue->isLastAttempt($job)),
			};
		} catch (Postponed $e) {
			$this->queue->postpone($job, $e->retryAfterSeconds);
			$this->logger->warning('Job postponed', $context + [
				'reason' => $e->getMessage(),
				'retry_after' => $e->retryAfterSeconds,
			]);

			return;
		} catch (\Throwable $e) {
			$status = $this->queue->fail($job, $e::class . ': ' . $e->getMessage());
			$context['status'] = $status->value;
			if ($status === JobStatus::Failed) {
				$this->logger->error('Job failed, no attempts left', $context, $e);
			} else {
				$this->logger->warning('Job failed, will retry', $context + [
					'exception' => $e::class,
				]);
			}

			return;
		}

		if (!$this->queue->finish($job)) {
			$this->logger->warning('Job finished after its reservation expired', $context);
		}
	}


	/**
	 * A failure is logged and retried at the next interval; it never stops the worker.
	 */
	private function applyRetention(): void
	{
		if ($this->lastRetention !== null && time() - $this->lastRetention < $this->retentionSeconds) {
			return;
		}

		$this->lastRetention = time();
		$this->correlation->renew();
		try {
			$deleted = array_filter($this->retention->purge());
		} catch (\Throwable $e) {
			$this->logger->error('Retention failed', [], $e);

			return;
		}

		if ($deleted !== []) {
			$this->logger->info('Retention deleted old data', $deleted);
		}
	}


	private function stop(): void
	{
		$this->running = false;
	}
}
