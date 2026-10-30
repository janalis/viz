<?php

declare(strict_types=1);

// Prepares a release, as the release workflow runs it: resolves the new version (an explicit
// X.Y.Z, or a patch, minor or major bump of the latest vX.Y.Z tag), turns CHANGELOG.md's
// "## Unreleased" section into "## X.Y.Z — YYYY-MM-DD", writes that section as the release
// notes, and prints `version=` and `tag=` lines for $GITHUB_OUTPUT.
//
//   php tools/release-prepare.php --bump minor --notes notes.md
//
// With --notes-for X.Y.Z it only writes a released version's section as notes, for
// publishing a release from a tag cut before.

$options = \getopt('', ['bump:', 'date:', 'notes:', 'notes-for:', 'changelog:', 'tags:']);
$changelog = $options['changelog'] ?? 'CHANGELOG.md';
$text = @\file_get_contents($changelog);
if (false === $text) {
    fail("cannot read {$changelog}");
}

if (isset($options['notes-for'])) {
    $version = \ltrim($options['notes-for'], 'v');
    $section = section($text, '/^## '.\preg_quote($version, '/').' — .*$/m') ?? fail("no section for {$version} in {$changelog}");
    write($options['notes'] ?? fail('--notes-for needs --notes'), $section[1]);
    exit(0);
}

$bump = $options['bump'] ?? fail('usage: release-prepare.php --bump X.Y.Z|patch|minor|major [--date YYYY-MM-DD] [--notes file] [--changelog file] [--tags v1,v2]');
$date = $options['date'] ?? \gmdate('Y-m-d');
if (1 !== \preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fail("bad date {$date}");
}
$tags = isset($options['tags']) ? \array_filter(\explode(',', $options['tags'])) : \preg_split('/\s+/', \trim((string) \shell_exec('git tag --list "v*"')), flags: \PREG_SPLIT_NO_EMPTY);
$version = next_version($bump, $tags);

$section = section($text, '/^## Unreleased[ \t]*$/mi') ?? fail("{$changelog} has no \"## Unreleased\" section");
[$heading, $body] = $section;
if ('' === \trim($body)) {
    fail("{$changelog}'s Unreleased section is empty");
}
write($changelog, \str_replace($heading, "## {$version} — {$date}", $text));
if (isset($options['notes'])) {
    write($options['notes'], $body);
}
echo "version={$version}\ntag=v{$version}\n";

/**
 * A changelog section: its heading as written, and its body up to the next version's heading.
 *
 * @return ?array{string, string}
 */
function section(string $text, string $heading): ?array
{
    if (1 !== \preg_match($heading, $text, $match, \PREG_OFFSET_CAPTURE)) {
        return null;
    }
    [$line, $at] = $match[0];
    $rest = \substr($text, $at + \strlen($line));
    $end = 1 === \preg_match('/^## /m', $rest, $next, \PREG_OFFSET_CAPTURE) ? $next[0][1] : \strlen($rest);

    return [$line, \trim(\substr($rest, 0, $end))];
}

/** The version a bump gives from the highest vX.Y.Z tag, 0.0.0 when there is none; an explicit one must be higher. */
function next_version(string $bump, array $tags): string
{
    $latest = [0, 0, 0];
    foreach ($tags as $tag) {
        $parsed = parse(\ltrim($tag, 'v'));
        if (null !== $parsed && $parsed > $latest) {
            $latest = $parsed;
        }
    }
    $next = match ($bump) {
        'major' => [$latest[0] + 1, 0, 0],
        'minor' => [$latest[0], $latest[1] + 1, 0],
        'patch' => [$latest[0], $latest[1], $latest[2] + 1],
        default => parse(\ltrim($bump, 'v')) ?? fail("bad version {$bump}: X.Y.Z, patch, minor or major"),
    };
    if ($next <= $latest) {
        fail(\sprintf('version %s is not above the latest tag v%s', \implode('.', $next), \implode('.', $latest)));
    }

    return \implode('.', $next);
}

/** @return ?array{int, int, int} */
function parse(string $version): ?array
{
    return 1 === \preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $version, $match) ? [(int) $match[1], (int) $match[2], (int) $match[3]] : null;
}

function write(string $file, string $content): void
{
    if (false === @\file_put_contents($file, \rtrim($content)."\n")) {
        fail("cannot write {$file}");
    }
}

function fail(string $message): never
{
    \fwrite(\STDERR, "release-prepare: {$message}\n");
    exit(1);
}
