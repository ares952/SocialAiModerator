<?php

declare(strict_types=1);

namespace App\Console;

use App\Operator\InvalidOperator;
use App\Operator\Operators;
use App\Operator\Role;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Creates an operator; used for the first admin when an instance is deployed (deploy/README.md).
 * Further operators are added in the administration.
 */
#[AsCommand(name: 'operator:create', description: 'Creates an administration account; asks for the password')]
final class OperatorCreateCommand extends Command
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
		$this->addArgument('name', InputArgument::REQUIRED, 'Display name');
		$roles = implode(', ', array_column(Role::cases(), 'value'));
		$this->addOption('role', null, InputOption::VALUE_REQUIRED, "Role: $roles", Role::Admin->value);
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$role = Role::tryFrom((string) $input->getOption('role'));
		if ($role === null) {
			$output->writeln('<error>Unknown role.</error>');

			return self::INVALID;
		}

		$password = $this->prompt->ask($this, $input, $output);
		if ($password === null) {
			return self::FAILURE;
		}

		try {
			$id = $this->operators->create(
				(string) $input->getArgument('email'),
				(string) $input->getArgument('name'),
				$role,
				$password,
				actorId: null,
			);
		} catch (InvalidOperator $e) {
			$output->writeln("<error>{$e->getMessage()}</error>");

			return self::FAILURE;
		}

		$output->writeln("operator $id created ($role->value)");

		return self::SUCCESS;
	}
}
