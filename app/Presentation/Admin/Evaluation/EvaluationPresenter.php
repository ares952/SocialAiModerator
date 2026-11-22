<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Evaluation;

use App\Evaluation\EvaluationRuns;
use App\Evaluation\EvaluationSamples;
use App\Evaluation\InvalidEvaluation;
use App\Evaluation\Sample;
use App\Meta\Platform;
use App\Moderation\Action;
use App\Moderation\Category;
use App\Moderation\ContentKind;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use App\Presentation\Admin\ChartExport;
use App\Presentation\Admin\Charts;
use Nette\Application\UI\Form;


/**
 * Evaluation set and runs (Milestone 2): moderators label samples, an admin starts a run, everyone
 * reads the metrics. Sample texts are shown only to those who may edit them. Charts: accuracy of
 * the last runs, and correct and wrong categories of one run.
 *
 * @phpstan-import-type Chart from Charts
 */
final class EvaluationPresenter extends AdminPresenter
{
	use ChartExport;

	/** Runs in the accuracy chart, the newest ones. */
	private const ChartRuns = 20;

	private ?int $sampleId = null;

	private ?int $runId = null;

	/** @var array{total: int, done: int, failed: int, pending: int, categoryCorrect: int, actionCorrect: int, automatic: int, criticismHidden: int, missedHandoff: int, falseHide: int, missedHide: int, inputTokens: int, outputTokens: int, confusion: array<string, array<string, int>>}|null */
	private ?array $report = null;


	public function __construct(
		private readonly EvaluationSamples $samples,
		private readonly EvaluationRuns $runs,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		$this->requireAllowed(Authorization::Evaluation, Authorization::View);
		$this->template->setParameters([
			'runs' => $this->runs->all(),
			'sampleCount' => $this->samples->count(),
			'canEdit' => $this->getUser()->isAllowed(Authorization::Evaluation, Authorization::Edit),
			'canRun' => $this->getUser()->isAllowed(Authorization::Evaluation, Authorization::Manage),
		]);
	}


	public function actionSamples(): void
	{
		$this->requireAllowed(Authorization::Evaluation, Authorization::Edit);
		$this->template->setParameters([
			'samples' => $this->samples->all(),
		]);
	}


	public function actionSample(?int $id = null): void
	{
		$this->requireAllowed(Authorization::Evaluation, Authorization::Edit);
		if ($id === null) {
			return;
		}

		$sample = $this->samples->find($id) ?? $this->error();
		$this->sampleId = $id;
		$form = $this->getComponent('sampleForm');
		assert($form instanceof Form);
		$form->setDefaults([
			'platform' => $sample->platform->value,
			'kind' => $sample->kind->value,
			'language' => $sample->language,
			'text' => $sample->text,
			'expectedCategory' => $sample->expectedCategory->value,
			'expectedAction' => $sample->expectedAction->value,
			'note' => $sample->note,
		]);
	}


	public function renderSample(?int $id = null): void
	{
		$this->template->setParameters([
			'sampleId' => $id,
		]);
	}


	public function renderDefault(): void
	{
		$this->template->setParameters([
			'charts' => $this->exportCharts(),
		]);
	}


	public function actionRun(int $id): void
	{
		$this->requireAllowed(Authorization::Evaluation, Authorization::View);
		$run = $this->runs->find($id) ?? $this->error();
		$this->runId = $id;
		$canEdit = $this->getUser()->isAllowed(Authorization::Evaluation, Authorization::Edit);
		$texts = [];
		if ($canEdit) {
			foreach ($this->samples->all() as $sample) {
				$texts[$sample->id] = $sample->text;
			}
		}

		$this->template->setParameters([
			'run' => $run,
			'report' => $this->report(),
			'results' => $this->runs->results($id),
			'texts' => $texts,
			'categories' => Category::cases(),
			'charts' => $this->exportCharts(),
		]);
	}


	/**
	 * Accuracy of the newest runs on the overview, correct and wrong categories on a run.
	 *
	 * @return array<string, Chart>
	 */
	protected function exportCharts(): array
	{
		if ($this->runId !== null) {
			$confusion = $this->report()['confusion'];
			$expected = array_values(array_filter(Category::cases(), fn(Category $category): bool => isset($confusion[$category->value])));
			$correct = array_map(fn(Category $category): int => $confusion[$category->value][$category->value] ?? 0, $expected);

			return [
				'categories' => $this->charts->chart('bar', array_map(
					fn(Category $category): string => $this->translator->translate("category.{$category->value}"),
					$expected,
				), [
					$this->charts->dataset('evaluation.chart.correct', 'success', $correct),
					$this->charts->dataset('evaluation.chart.wrong', 'danger', array_map(
						fn(Category $category, int $right): int => array_sum($confusion[$category->value]) - $right,
						$expected,
						$correct,
					)),
				], [
					'stacked' => true,
					'horizontal' => true,
				]),
			];
		}

		$runs = array_reverse(array_slice($this->runs->all(), 0, self::ChartRuns));
		$percent = fn(mixed $part, mixed $done): ?float => (int) $done === 0 ? null : round(100 * (int) $part / (int) $done, 1);

		return [
			'accuracy' => $this->charts->chart('bar', array_map(
				fn($run): string => "#{$run->id} {$run->model}",
				$runs,
			), [
				$this->charts->dataset('evaluation.metric.category', 'primary', array_map(fn($run): ?float => $percent($run->category_correct, $run->done), $runs)),
				$this->charts->dataset('evaluation.metric.action', 'teal', array_map(fn($run): ?float => $percent($run->action_correct, $run->done), $runs)),
			], [
				'unit' => '%',
			], $this->translator->translate('evaluation.chart.accuracy')),
		];
	}


	public function handleStart(): void
	{
		$this->requireAllowed(Authorization::Evaluation, Authorization::Manage);
		try {
			$runId = $this->runs->start($this->operatorId());
		} catch (InvalidEvaluation $e) {
			$this->flashMessage($e->getMessage(), 'danger');
			$this->redirect('this');
		}

		$this->flashMessage('evaluation.started', 'success');
		$this->redirect('run', [
			'id' => $runId,
		]);
	}


	public function handleDelete(int $id): void
	{
		$this->requireAllowed(Authorization::Evaluation, Authorization::Edit);
		$this->samples->delete($id, $this->operatorId());
		$this->flashMessage('evaluation.sample.deleted', 'success');
		$this->redirect('samples');
	}


	protected function createComponentSampleForm(): Form
	{
		$form = $this->forms->create();
		$form->addSelect('platform', 'evaluation.sample.platform', self::options(Platform::cases(), 'platform'));
		$form->addSelect('kind', 'evaluation.sample.kind', self::options(ContentKind::cases(), 'content.kind'));
		$form->addText('language', 'evaluation.sample.language')
			->setRequired('form.error.required')
			->addRule(Form::Pattern, 'evaluation.error.language', '[a-z]{2}')
			->setDefaultValue('cs');
		$form->addTextArea('text', 'evaluation.sample.text', rows: 5)
			->setRequired('form.error.required')
			->setOption('description', 'evaluation.sample.text-note');
		$form->addSelect('expectedCategory', 'evaluation.sample.expectedCategory', self::options(Category::cases(), 'category'));
		$form->addSelect('expectedAction', 'evaluation.sample.expectedAction', self::options(Action::cases(), 'action'));
		$form->addText('note', 'evaluation.sample.note')
			->setMaxLength(500);
		$form->addSubmit('send', 'evaluation.sample.save');

		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Evaluation, Authorization::Edit);
			$values = $form->getValues();
			try {
				$sample = new Sample(
					platform: Platform::from((string) $values['platform']),
					kind: ContentKind::from((string) $values['kind']),
					language: (string) $values['language'],
					text: (string) $values['text'],
					expectedCategory: Category::from((string) $values['expectedCategory']),
					expectedAction: Action::from((string) $values['expectedAction']),
					note: (string) $values['note'],
				);
			} catch (InvalidEvaluation $e) {
				$form->addError($e->getMessage());

				return;
			}

			if ($this->sampleId === null) {
				$this->samples->create($sample, $this->operatorId());
			} else {
				$this->samples->update($this->sampleId, $sample, $this->operatorId());
			}
			$this->flashMessage('evaluation.sample.saved', 'success');
			$this->redirect('samples');
		};

		return $form;
	}


	/**
	 * @param list<\BackedEnum> $cases
	 * @return array<string, string> value => translation key
	 */
	private static function options(array $cases, string $prefix): array
	{
		$options = [];
		foreach ($cases as $case) {
			$options[(string) $case->value] = "$prefix.$case->value";
		}

		return $options;
	}


	/**
	 * @return array{
	 *     total: int, done: int, failed: int, pending: int,
	 *     categoryCorrect: int, actionCorrect: int, automatic: int,
	 *     criticismHidden: int, missedHandoff: int, falseHide: int, missedHide: int,
	 *     inputTokens: int, outputTokens: int,
	 *     confusion: array<string, array<string, int>>
	 * }
	 */
	private function report(): array
	{
		return $this->report ??= $this->runs->report($this->runId ?? throw new \LogicException('No run is shown.'));
	}
}
