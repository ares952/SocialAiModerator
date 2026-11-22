<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Account\Accounts;
use Nette\Database\Row;


/**
 * GET filters of administration lists and charts: a filter form sends "all" as an empty value,
 * which redirects to the clean URL; an unknown account is a 404 like any invalid filter value.
 */
trait ListFilters
{
	private Accounts $filterAccounts;


	public function injectListFilters(Accounts $accounts): void
	{
		$this->filterAccounts = $accounts;
	}


	/**
	 * @param array<string, ?string> $filters parameter => value
	 */
	protected function cleanFilters(array $filters): void
	{
		$empty = array_filter($filters, fn(?string $value): bool => $value === '');
		if ($empty !== []) {
			$this->redirect('this', array_map(fn(): null => null, $empty));
		}
	}


	protected function accountFilter(?string $account): ?int
	{
		if ($account === null) {
			return null;
		}

		$id = filter_var($account, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 1,
			],
		]);

		return $id !== false && $this->filterAccounts->find($id) !== null ? $id : $this->error();
	}


	/**
	 * @return list<Row> accounts for the filter select
	 */
	protected function filterAccounts(): array
	{
		return $this->filterAccounts->all();
	}
}
