# Contributing

Thank you for wanting to help with the Unraid Secretary Office. Contributions come as pull requests from a fork; nothing
goes into `main` without a review.

## How

1. **Talk first.** Open an issue (or comment on an existing one) and say what you would like to build. For a larger piece,
   such as a new desk, agree on the scope there before writing code.
2. **Fork and branch.** Fork `dropnook/UnraidSecretaryOffice`, work on a branch of your own in your fork, and keep it close
   to `main` (merge or rebase `main` now and then; it moves fast).
3. **Pull request.** Describe what changed and what you tested, and on which Unraid version. The maintainers review it,
   run the test suite and try it on a test server; then it is merged and goes out with the next release.

No access to anything else is needed: no token, no server, no other repository.

## The rules for the code

Read `CLAUDE.md` (conventions, the checklist for a change, what bites on Unraid) and `docs/DEVELOPMENT.md` (how the
plugin and a desk are built). In short:

* **No build step, no dependencies:** plain PHP (Unraid's own), vanilla JavaScript, one CSS file plus a desk's own.
* **A desk lives in its own folder:** `public/desks/<id>/` (desk.json, desk.js, lang/, places.json, avatar.svg) and
  `agent/desks/<id>.php`. Shared files (`public/assets/core.js`, `office.css`, `src/`, `agent/lib/`, `backup/`) only
  where it can't be avoided — say so in the pull request.
* **Every text in all five languages** (`en`, `de`, `it`, `fr`, `es`), never hard-coded in JS or PHP; Unraid's own
  labels as `⟦English label⟧` tokens.
* **The web side never touches the host;** everything that needs the system goes through the agent. Never wake sleeping
  disks, never block the array stop, never change a setting without a preview and a confirmation.
* **Tests:** `php tests/run.php <test> …` on an Unraid server (a copy of the repository is enough; it never touches the
  live plugin) must end with 0 failed; add tests for what you build. A page change: `bash tools/ui-clicks.sh` (Node and
  a local Chrome).
* **Nothing personal in public files:** no names of people, servers, devices or paths of your own in code, comments,
  test data or commit messages — test data uses invented names.

## Unraid versions

The office is tested on Unraid 7.3. Work that depends on a newer Unraid says so in its desk's `fit` (it isn't offered
below that version) — Ms. Moverelli is built and tested on Unraid 7.4 only (#16).

## License

By contributing you agree that your contribution is published under the project's license, GPL-3.0-or-later.
