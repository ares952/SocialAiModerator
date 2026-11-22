<?php

declare(strict_types=1);

namespace App\Console;

use App\Account\Accounts;
use App\Account\TokenProvider;
use App\Meta\GraphReader;
use App\Meta\Platform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Reads one comment from Meta with the account's stored token (only reads) to verify fields,
 * token type and permissions live (docs/meta-checklist.md §8). Prints which fields came back and
 * the length of the text, never the customer's text.
 */
#[AsCommand(name: 'meta:read', description: 'Reads one comment from Meta with the account token (read only)')]
final class MetaReadCommand extends Command
{
	public function __construct(
		private readonly Accounts $accounts,
		private readonly TokenProvider $tokens,
		private readonly GraphReader $graph,
	) {
		parent::__construct();
	}


	protected function configure(): void
	{
		$this->addArgument('account', InputArgument::REQUIRED, 'Account ID in the administration');
		$this->addArgument('comment', InputArgument::REQUIRED, 'Comment ID with the platform prefix (fb:… or ig:…)');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$account = $this->accounts->find((int) $input->getArgument('account'));
		if ($account === null) {
			$output->writeln('Unknown account.');

			return self::FAILURE;
		}

		$platform = Platform::from((string) $account->platform);
		$comment = $this->graph->comment($platform, $this->tokens->token((int) $account->id), (string) $input->getArgument('comment'));
		$output->writeln("id: $comment->id");
		$output->writeln('text: ' . ($comment->text === null ? 'not returned' : mb_strlen($comment->text) . ' characters'));
		$output->writeln('author: ' . ($comment->authorId ?? 'not returned'));
		$output->writeln('created: ' . ($comment->createdAt?->format('Y-m-d H:i:s') ?? 'not returned'));
		$output->writeln('hidden: ' . ($comment->hidden === null ? 'not returned' : var_export($comment->hidden, true)));
		$output->writeln('can hide: ' . ($comment->canHide === null ? 'not returned' : var_export($comment->canHide, true)));
		$output->writeln('parent: ' . ($comment->parentId ?? 'none'));

		return self::SUCCESS;
	}
}
