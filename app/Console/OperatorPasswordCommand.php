<?php

declare(strict_types=1);

namespace App\Console;

use App\Operator\InvalidOperator;
use App\Operator\Operators;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Sets a new password when nobody can sign in any more (e.g. the only admin forgot it).
 * Signs the operator out of all sessions.
 */
#[AsCommand(name: 'operator:password', description: 'Sets a new password of an administration account')]
final class OperatorPasswordCommand extends Command
{
	public function __construct(
		private readonly Operators $operators,
		private readonly PasswordPrompt $prompt,
	) {
		parent::__construct();
	}


	protected function configure(): void
	{
		$this->addArgument('email', InputArgument::REQUIRED, 'Sign-in e-mail');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$operator = $this->operators->findByEmail((string) $input->getArgument('email'));
		if ($operator === null) {
			$output->writeln('<error>operator.error.not-found</error>');

			return self::FAILURE;
		}

		$password = $this->prompt->ask($this, $input, $output);
		if ($password === null) {
			return self::FAILURE;
		}

		try {
			$this->operators->changePassword($operator->id, $password, actorId: null);
		} catch (InvalidOperator $e) {
			$output->writeln("<error>{$e->getMessage()}</error>");

			return self::FAILURE;
		}

		$output->writeln("password of operator $operator->id changed, all sessions signed out");

		return self::SUCCESS;
	}
}
