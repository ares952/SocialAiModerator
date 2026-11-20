<?php

declare(strict_types=1);

namespace App\Console;

use App\Operator\Operators;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;


/**
 * Asks twice for a password without echoing it; never accepted as an argument, so it does not
 * end up in the shell history or the process list.
 */
final class PasswordPrompt
{
	public function ask(Command $command, InputInterface $input, OutputInterface $output): ?string
	{
		$helper = $command->getHelper('question');
		assert($helper instanceof QuestionHelper);

		$question = new Question(sprintf('Password (at least %d characters): ', Operators::MinPasswordLength));
		$question->setHidden(true);
		$password = (string) $helper->ask($input, $output, $question);

		$question = new Question('Repeat the password: ');
		$question->setHidden(true);
		if ((string) $helper->ask($input, $output, $question) !== $password) {
			$output->writeln('<error>The passwords do not match.</error>');

			return null;
		}

		return $password;
	}
}
