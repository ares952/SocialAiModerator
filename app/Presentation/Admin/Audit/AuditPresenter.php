<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Audit;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use Nette\Utils\Paginator;


/**
 * Audit trail of sensitive actions (admin only).
 */
final class AuditPresenter extends AdminPresenter
{
	private const PerPage = 50;


	public function __construct(
		private readonly AuditLog $audit,
	) {
		parent::__construct();
	}


	/**
	 * @param string|null $type audit action to filter by (`action` is reserved by Nette)
	 */
	public function actionDefault(?string $type = null, int $page = 1): void
	{
		$this->requireAllowed(Authorization::Audit, Authorization::View);

		$filter = $type === null ? null : AuditAction::tryFrom($type) ?? $this->error();
		$paginator = new Paginator;
		$paginator->setItemsPerPage(self::PerPage);
		$paginator->setItemCount($this->audit->count($filter));
		$paginator->setPage($page);

		$this->template->setParameters([
			'filter' => $filter,
			'actions' => AuditAction::cases(),
			'paginator' => $paginator,
			'entries' => $this->audit->page($filter, $paginator->getLength(), $paginator->getOffset()),
		]);
	}
}
