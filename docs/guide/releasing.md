# Releasing

Changes are written under `## Unreleased` at the top of `CHANGELOG.md` as they land.

To release, run the **Release** workflow from the Actions tab, on `main`, with a version: an explicit `X.Y.Z`, or `patch`, `minor` or `major` to bump the latest tag. It then:

1. runs the checks a release must pass: validation, formatting, lint, static analysis and the test suite;
2. turns the `## Unreleased` section into `## X.Y.Z — YYYY-MM-DD` (`tools/release-prepare.php`), commits it as `Release vX.Y.Z` and pushes an annotated `vX.Y.Z` tag;
3. publishes the GitHub release, its notes that changelog section;
4. tells Packagist, when the `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` secrets are set (Packagist's GitHub hook picks the tag up anyway);
5. rebuilds this documentation, so its version and changelog are current.

A dry run prepares everything and publishes nothing. A failed publish can be retried with "Re-run failed jobs", without cutting the release again.

To publish the release notes of a tag cut before, write them with `php tools/release-prepare.php --notes-for X.Y.Z --notes notes.md` and pass them to `gh release create`.
