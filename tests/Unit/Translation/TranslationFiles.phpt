<?php

/**
 * The shared data/language_<code>.json files are valid, define all plural forms and contain
 * the same set of keys in every UI language.
 */

declare(strict_types=1);

use App\Translation\TranslationFile;
use Nette\Neon\Neon;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


$root = dirname(__DIR__, 3);
$config = Neon::decodeFile("$root/config/common.neon");
$languages = $config['parameters']['languages'];

$keys = [];
foreach ($languages as $language) {
	$file = TranslationFile::load("$root/data", $language);
	$keys[$language] = array_keys($file->texts);
	sort($keys[$language]);
}

foreach ($languages as $language) {
	Assert::same($keys[$languages[0]], $keys[$language], "Keys of '$language' differ from '{$languages[0]}'.");
}
