<?php

declare(strict_types=1);

use App\Presentation\FormFactory;
use App\Translation\Translator;
use Nette\Application\UI\Form;
use Tester\Assert;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

// Bootstrap 5 markup of administration forms.

$form = (new FormFactory(new Translator(TestDatabase::create(), ['cs', 'en'])))->create();
$form->removeComponent($form[Form::ProtectorId]); // needs a session; not part of the markup check
$form->addCheckbox('active', 'Active');
$form->addCheckboxList('categories', 'Categories', [
	'spam' => 'Spam',
	'faq' => 'FAQ',
]);
$html = (string) $form;


test('a checkbox is a switch', function () use ($html): void {
	Assert::match(
		'%A%<div class="form-check form-switch"><label class="form-check-label" for="frm-active"><input type="checkbox" name="active" class="form-check-input" role="switch" id="frm-active">Active</label></div>%A%',
		$html,
	);
});


test('a checkbox list has its caption on its own line and one switch per line', function () use ($html): void {
	Assert::match('%A%<label class="form-label d-block">Categories</label>%A%', $html);
	Assert::same(2, substr_count($html, '<div class="form-check form-switch"><label class="form-check-label"><input type="checkbox" name="categories[]" class="form-check-input" role="switch"'));
});
