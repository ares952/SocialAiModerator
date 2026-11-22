<?php

declare(strict_types=1);

use App\Comment\Comments;
use App\Console\EventsNormalizeCommand;
use App\Audit\AuditLog;
use App\Conversation\Conversations;
use App\Core\Encryptor;
use App\Ingest\WebhookBatch;
use App\Meta\EventNormalizer;
use App\Queue\JobQueue;
use App\Retention\Retention;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Symfony\Component\Console\Tester\CommandTester;
use Tester\Assert;
use Tests\Support\TestDatabase;
use Tests\Support\TestServices;

require __DIR__ . '/../../bootstrap.php';

// Stored events -> worker -> current state of posts, comments, conversations and messages
// (specification §3.3, §3.5, §6): repeated and out-of-order delivery, own echoes, the reply window.
// Synthetic payloads only (tests/fixtures/meta).

$database = TestDatabase::create();
$encryptor = new Encryptor(base64_encode(random_bytes(32)));
$queue = new JobQueue(
	$database,
	maxAttempts: 2,
	backoffSeconds: 10,
	backoffCapSeconds: 25,
	leaseSeconds: 600,
);
foreach ([['facebook', 'fb:1000000000000001'], ['instagram', 'ig:1700000000000001']] as [$platform, $externalId]) {
	$database->query('INSERT INTO social_accounts ?', [
		'platform' => $platform,
		'external_id' => $externalId,
		'name' => 'Test account',
		'status' => 'active',
	]);
}

// Stores and processes one webhook body in order of its items; returns the last event ID.
$receive = static function (string $body) use ($database, $encryptor, $queue): int {
	TestServices::eventStore($database, $encryptor, $queue)->save(WebhookBatch::parse($body));
	$ids = $database->query("SELECT id FROM social_events WHERE status = 'received' ORDER BY id")->fetchPairs(null, 'id');
	foreach ($ids as $id) {
		TestServices::eventProcessor($database, $encryptor, $queue)->process($id);
	}

	return (int) $database->query('SELECT MAX(id) FROM social_events')->fetchField();
};
$fixture = static fn (string $name): int => $receive(FileSystem::read(__DIR__ . "/../../fixtures/meta/$name.json"));

$comment = static fn (string $externalId) => $database->query(
	'SELECT c.*, p.external_id AS post, p.permalink FROM social_comments c LEFT JOIN social_posts p ON p.id = c.post_id WHERE c.external_id = ?',
	$externalId,
)->fetch() ?? throw new LogicException("Comment $externalId is missing.");
$commentText = static fn (string $externalId): ?string => ($text = $comment($externalId)->text_encrypted) === null
	? null
	: $encryptor->decrypt($text, Comments::TextContext);
$messageText = static fn (?string $text): ?string => $text === null ? null : $encryptor->decrypt($text, Conversations::TextContext);


test('Facebook comment events in any order end in the newest state', function () use ($fixture, $comment, $commentText): void {
	$fixture('page-comment-hidden'); // 12:04, arrives first
	$fixture('page-comment'); // 12:00
	$fixture('page-comment-edited'); // 12:02

	$row = $comment('fb:2200000000000001_2300000000000001');
	Assert::same('hidden', $row->visibility);
	Assert::same('Synthetic comment, edited.', $commentText('fb:2200000000000001_2300000000000001'), 'The older create does not overwrite the edit.');
	Assert::same('fb:1000000000000001_2200000000000001', $row->post);
	Assert::same('https://www.facebook.com/example/posts/1', $row->permalink);
	Assert::same('fb:2100000000000001', $row->author_external_id);
	Assert::null($row->parent_external_id, 'A top-level comment has the post as its parent in the payload.');
	Assert::same(0, $row->own);

	$fixture('page-comment-reply');
	Assert::same('fb:2200000000000001_2300000000000001', $comment('fb:2200000000000001_2300000000000002')->parent_external_id);

	$fixture('page-comment-removed'); // 12:03, older than the hide, still final
	$row = $comment('fb:2200000000000001_2300000000000001');
	Assert::same('removed', $row->visibility);
	Assert::null($row->text_encrypted, 'A removed comment loses its text.');
});


test('a removed comment stays removed and never gets its text back', function () use ($receive, $comment): void {
	$receive(Json::encode([
		'object' => 'page',
		'entry' => [
			[
				'id' => '1000000000000001',
				'changes' => [
					['field' => 'feed', 'value' => ['item' => 'comment', 'verb' => 'unhide', 'comment_id' => '2200000000000001_2300000000000001', 'created_time' => 1763650000]],
					['field' => 'feed', 'value' => ['item' => 'comment', 'verb' => 'edited', 'comment_id' => '2200000000000001_2300000000000001', 'created_time' => 1763650001, 'message' => 'Synthetic late edit']],
				],
			],
		],
	]));
	$row = $comment('fb:2200000000000001_2300000000000001');
	Assert::same(['removed', null], [$row->visibility, $row->text_encrypted]);
});


test('Instagram comments know their media, parent and author; a duplicate changes nothing', function () use ($fixture, $comment, $commentText, $database): void {
	$fixture('instagram-comment');
	$fixture('instagram-comment-reply');
	$top = $comment('ig:1800000000000001');
	$reply = $comment('ig:1800000000000002');
	Assert::same(['ig:1790000000000001', null, 'ig:1780000000000001', 'visible'], [$top->post, $top->parent_external_id, $top->author_external_id, $top->visibility]);
	Assert::same(['ig:1790000000000001', 'ig:1800000000000001'], [$reply->post, $reply->parent_external_id]);
	Assert::same('Synthetic comment: how much is the course?', $commentText('ig:1800000000000001'));

	$before = $database->query('SELECT COUNT(*) FROM social_events')->fetchField();
	$fixture('instagram-comment');
	Assert::same($before, $database->query('SELECT COUNT(*) FROM social_events')->fetchField(), 'Deduplicated by event_key.');
	Assert::same(1, $database->query("SELECT COUNT(*) FROM social_posts WHERE external_id = 'ig:1790000000000001'")->fetchField());
});


test('messages form one conversation per account and customer; the window follows customer messages only', function () use ($fixture, $database, $messageText): void {
	$fixture('instagram-message-deleted'); // unsent at 12:02, arrives first
	$fixture('instagram-message'); // sent at 12:00
	$echo = $fixture('instagram-message-echo');

	$conversation = $database->query("SELECT * FROM social_conversations WHERE customer_external_id = 'ig:5100000000000001'")->fetch() ?? throw new LogicException;
	Assert::same('2025-11-20 12:00:00.500', $conversation->last_customer_message_at?->format('Y-m-d H:i:s.v'));
	Assert::same('2025-11-21 12:00:00.500', $conversation->window_expires_at?->format('Y-m-d H:i:s.v'), 'The echo does not move the window.');

	$messages = $database->query('SELECT * FROM social_messages WHERE conversation_id = ? ORDER BY id', $conversation->id)->fetchAll();
	Assert::count(2, $messages);
	Assert::same(['incoming', null, '2025-11-20 12:02:00.500'], [$messages[0]->direction, $messages[0]->text_encrypted, $messages[0]->deleted_at?->format('Y-m-d H:i:s.v')], 'The unsent message never gets its text back.');
	Assert::same(['outgoing', 'Synthetic reply from the account'], [$messages[1]->direction, $messageText($messages[1]->text_encrypted)]);
	Assert::same('ignored', $database->query('SELECT status FROM social_events WHERE id = ?', $echo)->fetchField());

	$fixture('page-message');
	Assert::same(2, $database->query('SELECT COUNT(*) FROM social_conversations')->fetchField(), 'Facebook and Instagram never share a conversation.');
});


test('an older customer message never shortens the window; a message without a verified time never opens it', function () use ($receive, $database): void {
	$message = static fn (string $mid, mixed $timestamp): string => Json::encode([
		'object' => 'instagram',
		'entry' => [
			[
				'id' => '1700000000000001',
				'messaging' => [
					[
						'sender' => ['id' => '5100000000000001'],
						'recipient' => ['id' => '1700000000000001'],
						'timestamp' => $timestamp,
						'message' => ['mid' => $mid, 'text' => 'Synthetic message'],
					],
				],
			],
		],
	]);
	$receive($message('m_older', 1763630000000));
	Assert::same('2025-11-21 12:00:00.500', $database->query("SELECT window_expires_at FROM social_conversations WHERE customer_external_id = 'ig:5100000000000001'")->fetchField()?->format('Y-m-d H:i:s.v'));

	$database->query("INSERT INTO social_accounts ?", ['platform' => 'instagram', 'external_id' => 'ig:1700000000000002', 'name' => 'Other', 'status' => 'active']);
	$receive(str_replace('1700000000000001', '1700000000000002', $message('m_untimed', 'not a time')));
	$conversation = $database->query("SELECT * FROM social_conversations WHERE customer_external_id = 'ig:5100000000000001' AND account_id = (SELECT id FROM social_accounts WHERE external_id = 'ig:1700000000000002')")->fetch() ?? throw new LogicException;
	Assert::same([null, null], [$conversation->last_customer_message_at, $conversation->window_expires_at]);
	Assert::null($database->query('SELECT sent_at FROM social_messages WHERE conversation_id = ?', $conversation->id)->fetchField());
});


test('events:normalize rebuilds the same state from stored events', function () use ($database, $encryptor, $queue): void {
	$snapshot = static fn (): array => [
		$database->query('SELECT external_id, post_id IS NOT NULL AS has_post, parent_external_id, visibility, text_encrypted IS NULL AS no_text FROM social_comments ORDER BY external_id')->fetchAll(),
		$database->query('SELECT customer_external_id, last_customer_message_at, window_expires_at FROM social_conversations ORDER BY account_id, customer_external_id')->fetchAll(),
		$database->query('SELECT m.external_id, direction, sent_at, deleted_at, text_encrypted IS NULL AS no_text FROM social_messages m ORDER BY m.external_id')->fetchAll(),
	];
	$before = Json::encode($snapshot());
	$database->query('DELETE FROM social_messages');
	$database->query('DELETE FROM social_conversations');
	$database->query('DELETE FROM social_comments');
	$database->query('DELETE FROM social_posts');

	$command = new CommandTester(new EventsNormalizeCommand($database, $encryptor, new EventNormalizer, TestServices::eventProcessor($database, $encryptor, $queue)));
	Assert::same(0, $command->execute(['--all' => true]));
	Assert::same($before, Json::encode($snapshot()));
});


test('retention deletes old comments, posts left without comments, old messages and empty conversations', function () use ($database): void {
	$database->query("UPDATE social_comments SET updated_at = NOW() - INTERVAL 40 DAY WHERE external_id LIKE 'fb:%'");
	$database->query('UPDATE social_posts SET created_at = NOW() - INTERVAL 40 DAY');
	$database->query("UPDATE social_messages SET created_at = NOW() - INTERVAL 40 DAY WHERE external_id LIKE 'fb:%'");
	$database->query('UPDATE social_conversations SET created_at = NOW() - INTERVAL 40 DAY');
	$database->query("INSERT INTO retention_periods (data, days) VALUES ('comments', 30), ('messages', 30)");

	(new Retention($database, new AuditLog($database)))->purge();

	Assert::same(0, $database->query("SELECT COUNT(*) FROM social_comments WHERE external_id LIKE 'fb:%'")->fetchField());
	Assert::same(2, $database->query("SELECT COUNT(*) FROM social_comments WHERE external_id LIKE 'ig:%'")->fetchField());
	Assert::same(['ig:1790000000000001'], $database->query('SELECT external_id FROM social_posts')->fetchPairs(null, 'external_id'));
	Assert::same(0, $database->query("SELECT COUNT(*) FROM social_messages WHERE external_id LIKE 'fb:%'")->fetchField());
	Assert::same(0, $database->query("SELECT COUNT(*) FROM social_conversations c JOIN social_accounts a ON a.id = c.account_id WHERE a.platform = 'facebook'")->fetchField());
	Assert::same(2, $database->query("SELECT COUNT(*) FROM social_conversations c JOIN social_accounts a ON a.id = c.account_id WHERE a.platform = 'instagram'")->fetchField());
});
