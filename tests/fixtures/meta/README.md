# Meta webhook fixtures

Synthetic payloads shaped after the official Meta webhook documentation (Graph API v27.0).
IDs and texts are invented; never put real payloads, IDs or customer content here.

| File | Object | Content |
| --- | --- | --- |
| `instagram-comment.json` | `instagram` | `comments` change: new comment on a post |
| `instagram-message.json` | `instagram` | `messaging`: incoming direct message |
| `instagram-message-echo.json` | `instagram` | `messaging`: echo of a message sent by the account |
| `instagram-message-deleted.json` | `instagram` | `messaging`: message unsent by the customer (same `mid`) |
| `page-comment.json` | `page` | `feed` change: new comment on a Page post |
| `page-message.json` | `page` | `messaging`: incoming Messenger message |
| `page-message-echo.json` | `page` | `messaging`: echo of a message sent by the Page |

Account IDs: Instagram `1700000000000001`, Page `1000000000000001`.
