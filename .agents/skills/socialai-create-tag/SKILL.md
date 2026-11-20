---
name: socialai-create-tag
description: Create and publish the annotated release tag vMAJOR.MINOR.PATCH of Social AI Moderator from version.txt, only from a clean main branch synchronized with origin/main. Use for requests to tag or release a version; deploying it is a separate skill (socialai-release-update).
---

# Social AI Moderator release tag

Respond in the user's language. Resolve the repository root with `git rev-parse --show-toplevel`,
read its `AGENTS.md` and **Vytvoření tagu** in [the release lifecycle](../../../docs/release-lifecycle.md).
That document is the source of truth; reread it at execution time. Installing or editing this
skill, or an analysis request, does not authorize creating or publishing a tag. A request to
create the release tag includes publishing it to `origin`.

## Verify prerequisites

1. `git symbolic-ref --quiet --short HEAD` is exactly `main`. Any other branch or a detached
   HEAD is a blocker: report it; never switch branches or create a substitute worktree.
2. `git status --porcelain=v1 --untracked-files=all` is empty. Never commit, stash, reset or
   clean the user's changes to pass this check.
3. `git fetch origin refs/heads/main:refs/remotes/origin/main --tags` succeeds and
   `git rev-parse HEAD` equals `git rev-parse refs/remotes/origin/main`. Report ahead, behind or
   diverged state; never push the branch, merge, rebase or force-update refs here.
4. `version.txt` is tracked and unchanged against `HEAD`.
   `VERSION=$(tr -d '[:space:]' < version.txt)` matches
   `^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$`. Never infer the version from existing tags
   and never bump it in this skill (use `socialai-version-bump`). `TAG="v$VERSION"`; record
   `RELEASE_COMMIT=$(git rev-parse HEAD)`.
5. Check `refs/tags/$TAG` locally and query
   `git ls-remote --tags origin "refs/tags/$TAG" "refs/tags/$TAG^{}"`. A failed query is a
   blocker, not proof of absence. An existing tag follows **Existing tag** below.
6. `docs/upgrade-notes.md` contains a section for `$VERSION` if the release needs any manual
   deployment step (ask the user when unsure; a missing note is reported, not invented).

Report branch, version, tag and commit. If tagging was explicitly requested, continue; otherwise
ask for permission for this concrete tag.

## Create, publish, verify

Immediately before creating the tag, recheck items 1–4. Then:

```sh
git tag -a "$TAG" -m "Release $TAG"
git push origin "$TAG"
```

Push only this tag: never `--tags`, a branch, `--force` or a deletion. Verify that
`git cat-file -t "refs/tags/$TAG"` is `tag`, `git rev-parse "$TAG^{commit}"` equals
`RELEASE_COMMIT`, and the remote query returns the same tag object and peeled commit.

If push or verification fails, keep the local tag, report local and remote state separately and
stop. Never delete, move or recreate a tag, and never claim publication without verification.

## Existing tag

Require an annotated tag whose peeled commit is `RELEASE_COMMIT` and whose `version.txt` equals
`VERSION`. Remote tag present with the same identity: report it as already published. Remote tag
absent and publication authorized: run only `git push origin "$TAG"` and verify. Any identity
conflict or lightweight tag: stop and report; a different release needs a new version.

Tagging does not deploy or validate a running instance; for that use `socialai-release-update`.
