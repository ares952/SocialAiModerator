<?php

declare(strict_types=1);

use App\Audit\AuditLog;
use App\Comment\Comments;
use App\Conversation\Conversations;
use App\Core\Encryptor;
use App\Privacy\CustomerData;
use Tester\Assert;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

// Data subject requests (specification §8.3): summary, export and deletion of one customer's data,
// nothing of other customers is touched. Synthetic data only.

$database = TestDatabase::create();
$encryptor = new Encryptor(base64_encode(random_bytes(32)));
$data = new CustomerData($database, $encryptor, new AuditLog($database));
$database->query('INSERT INTO operators ?', [
	'email' => 'admin@example.test',
	'name' => 'Admin',
	'role' => 'admin',
	'password_hash' => 'x',
]);
$database->query('INSERT INTO social_accounts ?', [
	'platform' => 'facebook',
	'external_id' => 'fb:1000000000000001',
	'name' => 'Test Page',
	'status' => 'active',
]);
$accountId = (int) $database->getInsertId();

$event = static function (string $externalId, string $type) use ($database, $accountId): int {
	$database->query('INSERT INTO social_events ?', [
		'platform' => 'facebook',
		'account_external_id' => 'fb:1000000000000001',
		'account_id' => $accountId,
		'event_key' => bin2hex(random_bytes(32)),
		'topic' => 'feed',
		'event_type' => $type,
		'external_id' => $externalId,
		'payload_encrypted' => 'x',
		'status' => 'review',
		'received_at' => new DateTimeImmutable,
	]);

	return (int) $database->getInsertId();
};

// The customer: one comment with an AI decision, an intervention and a queued send, one conversation.
foreach (['fb:c1' => 'fb:2100000000000001', 'fb:c2' => 'fb:2100000000000002'] as $commentId => $author) {
	$database->query('INSERT INTO social_comments ?', [
		'account_id' => $accountId,
		'external_id' => $commentId,
		'author_external_id' => $author,
		'text_encrypted' => $encryptor->encrypt("Synthetic comment $commentId", Comments::TextContext),
		'visibility' => 'visible',
	]);
	$eventId = $event($commentId, 'comment.created');
	$database->query('INSERT INTO moderation_decisions ?', [
		'event_id' => $eventId,
		'model' => 'model-a',
		'prompt_version' => 'classifier-v2',
		'policy_version' => 0,
		'category' => 'faq',
		'action' => 'review',
		'automatic' => false,
		'result_json' => '{}',
	]);
}
$customerComment = (int) $database->query("SELECT id FROM social_comments WHERE external_id = 'fb:c1'")->fetchField();
$database->query('INSERT INTO moderation_actions ?', [
	'comment_id' => $customerComment,
	'type' => 'hide',
	'status' => 'approved',
	'automatic' => false,
	'external_target_id' => 'fb:c1',
]);
$actionId = (int) $database->getInsertId();
$database->query('INSERT INTO queue_jobs ?', [
	'type' => 'action.send',
	'subject_id' => $actionId,
	'status' => 'pending',
	'available_at' => new DateTimeImmutable,
]);
$database->query('INSERT INTO social_conversations ?', [
	'account_id' => $accountId,
	'customer_external_id' => 'fb:2100000000000001',
]);
$conversationId = (int) $database->getInsertId();
foreach (['incoming' => 'fb:m1', 'outgoing' => 'fb:m2'] as $direction => $mid) {
	$database->query('INSERT INTO social_messages ?', [
		'conversation_id' => $conversationId,
		'external_id' => $mid,
		'direction' => $direction,
		'text_encrypted' => $encryptor->encrypt("Synthetic $direction message", Conversations::TextContext),
	]);
}
$event('fb:m1', 'message.received');


test('only IDs with the platform prefix are accepted', function () use ($data): void {
	Assert::true(CustomerData::isValidId('fb:2100000000000001'));
	Assert::true(CustomerData::isValidId('ig:17841400000000000'));
	Assert::false(CustomerData::isValidId('2100000000000001'));
	Assert::false(CustomerData::isValidId("fb:1' OR 1=1"));
	Assert::exception(fn() => $data->summary('x:1'), InvalidArgumentException::class);
});


test('the summary and the export cover the comments, the conversation and the AI decisions', function () use ($data, $database): void {
	Assert::same([
		'comments' => 1,
		'conversations' => 1,
		'messages' => 2,
		'events' => 2,
	], $data->summary('fb:2100000000000001'));

	$export = $data->export('fb:2100000000000001', 1);
	Assert::same('fb:2100000000000001', $export['customer']);
	Assert::same(['Synthetic comment fb:c1'], array_column($export['comments'], 'text'));
	Assert::same(['Synthetic incoming message', 'Synthetic outgoing message'], array_column($export['messages'], 'text'));
	Assert::same([['fb:c1', 'faq', 'review']], array_map(fn(array $row): array => [$row['content_id'], $row['category'], $row['action']], $export['ai_decisions']));

	$audit = $database->query("SELECT details FROM admin_audit WHERE action = 'privacy.export'")->fetchField();
	Assert::match('sha256:%h%', (string) $audit);
	Assert::notContains('2100000000000001', (string) $audit, 'The audit never stores the customer ID.');
});


test('deletion removes the customer, keeps the others and is audited with the counts', function () use ($data, $database, $actionId): void {
	Assert::same([
		'comments' => 1,
		'conversations' => 1,
		'messages' => 2,
		'events' => 2,
	], $data->delete('fb:2100000000000001', 1));
	Assert::same([
		'comments' => 0,
		'conversations' => 0,
		'messages' => 0,
		'events' => 0,
	], $data->summary('fb:2100000000000001'));
	Assert::same(0, (int) $database->query('SELECT COUNT(*) FROM moderation_actions')->fetchField());
	Assert::same(0, (int) $database->query('SELECT COUNT(*) FROM queue_jobs WHERE subject_id = ?', $actionId)->fetchField());
	Assert::same(1, (int) $database->query('SELECT COUNT(*) FROM moderation_decisions')->fetchField(), 'The other customer keeps their decision.');
	Assert::same(1, $data->summary('fb:2100000000000002')['comments']);
	Assert::match('sha256:%h%; comments=1, conversations=1, messages=2, events=2', (string) $database->query(
		"SELECT details FROM admin_audit WHERE action = 'privacy.delete'",
	)->fetchField());
});
