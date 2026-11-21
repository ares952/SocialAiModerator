# Meta webhook fixtures

Synthetic payloads shaped after the official Meta webhook documentation (Graph API v27.0) and
checked against the key structure of real deliveries (keys only, no values were copied). IDs and
texts are invented; never put real payloads, IDs or customer content here.

| File | Object | Content |
| --- | --- | --- |
| `instagram-comment.json` | `instagram` | `comments` change: new comment on a post |
| `instagram-comment-reply.json` | `instagram` | `comments` change: reply to a comment (`parent_id`) |
| `instagram-message.json` | `instagram` | `messaging`: incoming direct message |
| `instagram-message-echo.json` | `instagram` | `messaging`: echo of a message sent by the account |
| `instagram-message-deleted.json` | `instagram` | `messaging`: message unsent by the customer (same `mid`) |
| `page-comment.json` | `page` | `feed` change: new comment on a Page post (`parent_id` = post) |
| `page-comment-reply.json` | `page` | `feed` change: reply to a comment (`parent_id` = comment) |
| `page-comment-edited.json` | `page` | `feed` change: comment edited (`verb: edited`) |
| `page-comment-removed.json` | `page` | `feed` change: comment removed (`verb: remove`) |
| `page-comment-hidden.json` | `page` | `feed` change: comment hidden (`verb: hide`) |
| `page-post.json` | `page` | `feed` change: post published by the Page itself |
| `page-reaction.json` | `page` | `feed` change: reaction to a post |
| `page-message.json` | `page` | `messaging`: incoming Messenger message |
| `page-message-echo.json` | `page` | `messaging`: echo of a message sent by the Page |
| `page-postback.json` | `page` | `messaging`: button postback |
| `page-message-read.json` | `page` | `messaging`: read receipt |
| `page-message-dashboard-test.json` | `page` | App Dashboard test button: `changes` with field `messages`, the timestamp as a string in seconds, Meta's public sample values, entry ID `0` |

Account IDs: Instagram `1700000000000001`, Page `1000000000000001`.

Times: `feed` and `comments` in seconds (`comments` has no time of its own, the entry time is
used); `messaging` in milliseconds.
