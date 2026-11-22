<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Review;

use App\Moderation\ReviewFilter;
use App\Moderation\ReviewQueue;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use Nette\Utils\Paginator;


/**
 * Review queue (specification §4.3): comments and messages that need a human, oldest first, with
 * the AI summary. The content, the interventions and resolving happen on the event detail.
 */
final class ReviewPresenter extends AdminPresenter
{
	private const PerPage = 50;


	public function __construct(
		private readonly ReviewQueue $queue,
	) {
		parent::__construct();
	}


	public function actionDefault(?string $filter = null, int $page = 1): void
	{
		$this->requireAllowed(Authorization::Events, Authorization::View);
		$current = $filter === null ? null : ReviewFilter::tryFrom($filter) ?? $this->error();
		$operatorId = $this->operatorId();
		$paginator = new Paginator;
		$paginator->setItemsPerPage(self::PerPage);
		$paginator->setItemCount($this->queue->count($current, $operatorId));
		$paginator->setPage($page);

		$counts = [
			'' => $current === null ? $paginator->getItemCount() : $this->queue->count(null, $operatorId),
		];
		foreach (ReviewFilter::cases() as $item) {
			$counts[$item->value] = $item === $current ? $paginator->getItemCount() : $this->queue->count($item, $operatorId);
		}

		$this->template->setParameters([
			'filter' => $current,
			'filters' => ReviewFilter::cases(),
			'counts' => $counts,
			'paginator' => $paginator,
			'items' => $this->queue->page($current, $operatorId, $paginator->getLength(), $paginator->getOffset()),
			'canAct' => $this->getUser()->isAllowed(Authorization::Events, Authorization::Edit),
		]);
	}
}
