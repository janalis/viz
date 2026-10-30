<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Tools;

use PHPUnit\Framework\TestCase;

final class ReleasePrepareTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir().'/viz-release-'.\bin2hex(\random_bytes(4));
        \mkdir($this->directory);
        \file_put_contents($this->directory.'/CHANGELOG.md', data: "# Changelog\n\n## Unreleased\n\n- A new thing.\n\n## 0.1.1 — 2026-10-19\n\n- A fix.\n");
    }

    protected function tearDown(): void
    {
        $files = \glob($this->directory.'/*');
        \array_map(\unlink(...), false === $files ? [] : $files);
        \rmdir($this->directory);
    }

    public function testABumpCutsTheUnreleasedSectionIntoTheNextVersion(): void
    {
        [$code, $output] = $this->run_('--bump', 'minor', '--tags', 'v0.1.0,v0.1.1', '--date', '2026-10-31', '--notes', $this->directory.'/notes.md');

        $this->assertSame(0, $code);
        $this->assertSame("version=0.2.0\ntag=v0.2.0\n", $output);
        $this->assertSame("# Changelog\n\n## 0.2.0 — 2026-10-31\n\n- A new thing.\n\n## 0.1.1 — 2026-10-19\n\n- A fix.\n", \file_get_contents($this->directory.'/CHANGELOG.md'));
        $this->assertSame("- A new thing.\n", \file_get_contents($this->directory.'/notes.md'));
    }

    public function testAnExplicitVersionMustBeAboveTheLatestTag(): void
    {
        $this->assertSame([0, "version=1.0.0\ntag=v1.0.0\n"], $this->run_('--bump', 'v1.0.0', '--tags', 'v0.1.1'));
        $this->assertSame(1, $this->run_('--bump', '0.1.1', '--tags', 'v0.1.1')[0]);
        $this->assertSame(1, $this->run_('--bump', 'soon', '--tags', 'v0.1.1')[0]);
    }

    public function testAReleasedVersionsNotesAreWrittenFromItsSection(): void
    {
        [$code] = $this->run_('--notes-for', 'v0.1.1', '--notes', $this->directory.'/old.md');

        $this->assertSame(0, $code);
        $this->assertSame("- A fix.\n", \file_get_contents($this->directory.'/old.md'));
        $this->assertSame(1, $this->run_('--notes-for', '0.0.9', '--notes', $this->directory.'/none.md')[0]);
    }

    public function testNoUnreleasedSectionIsNoRelease(): void
    {
        \file_put_contents($this->directory.'/CHANGELOG.md', data: "# Changelog\n\n## 0.1.1 — 2026-10-19\n\n- A fix.\n");

        $this->assertSame(1, $this->run_('--bump', 'patch', '--tags', 'v0.1.1')[0]);
    }

    /** @return array{int, string} the exit code and what it printed */
    private function run_(string ...$arguments): array
    {
        $command = [\PHP_BINARY, \dirname(__DIR__, levels: 2).'/tools/release-prepare.php', '--changelog', $this->directory.'/CHANGELOG.md', ...$arguments];
        $output = [];
        $code = 0;
        \exec(\implode(' ', \array_map(\escapeshellarg(...), $command)).' 2>/dev/null', $output, $code);

        return [$code, \implode('', \array_map(static fn (string $line): string => $line."\n", $output))];
    }
}
