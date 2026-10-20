<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\GraphViz\DotImages;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\GraphViz\Drawing\DotNodes;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DotImagesTest extends TestCase
{
    private string $root = '';

    #[\Override]
    protected function setUp(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not installed');
        }
        $this->root = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.'viz-images-'.\bin2hex(\random_bytes(6));
        \mkdir($this->root.'/allowed/inner', recursive: true);
        \mkdir($this->root.'/outside');
        self::png($this->root.'/allowed/red.png', 100, 50);
        self::png($this->root.'/allowed/inner/blue.png', 20, 20);
        self::png($this->root.'/outside/secret.png', 10, 10);
        \file_put_contents($this->root.'/allowed/notes.txt', data: 'not an image');
        $gif = \imagecreatetruecolor(width: 4, height: 4);
        \imagegif($gif, $this->root.'/allowed/small.gif');
        \symlink($this->root.'/outside/secret.png', $this->root.'/allowed/link.png');
        self::png($this->root.'/allowed/wide.png', DotImages::MOST_PIXELS + 1, 1);
        // A file one byte past the most, sparse, so the test writes almost nothing.
        $large = \fopen($this->root.'/allowed/large.png', mode: 'w');
        \assert(false !== $large, description: 'the file opens');
        \ftruncate($large, DotImages::MOST_BYTES + 1);
        \fclose($large);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testImagesAreReadOnlyFromInsideTheAllowedDirectories(): void
    {
        $images = new DotImages([$this->root.'/allowed', $this->root.'/missing']);

        $red = $images->read('red.png');
        $this->assertNotNull($red);
        $this->assertSame(['image/png', 75.0, 37.0], [$red->type, $red->width, $red->height], 'at 96 pixels to the inch, in whole points, as dot measures them');
        $this->assertStringStartsWith('data:image/png;base64,', $red->uri());
        $this->assertSame(15.0, $images->read('inner/blue.png')?->width);
        $this->assertSame('image/gif', $images->read($this->root.'/allowed/small.gif')?->type, 'an absolute path inside');
        foreach ([
            '../outside/secret.png',
            $this->root.'/outside/secret.png',
            'link.png',
            'notes.txt',
            'missing.png',
            'https://example.com/x.png',
            'file:///etc/passwd',
            '',
            "red.png\0",
            'wide.png',
            'large.png',
        ] as $refused) {
            $this->assertNull($images->read($refused), $refused);
        }
        $this->assertNull(new DotImages()->read('red.png'), 'no directory, nothing read');
    }

    public function testTheDotPipelineDrawsImagesItMayReadAndLeavesTheRestToDot(): void
    {
        $pipeline = new DotPipeline([$this->root.'/allowed']);
        $drawing = Work::run($pipeline->drawing(
            'digraph { a [image="red.png", shape=box]; b [image="inner/blue.png", imagepos=tl, imagescale=both]; c [shape=plaintext, label=<<TABLE><TR><TD><IMG SRC="red.png"/></TD></TR></TABLE>>]; a -> b }',
        ));
        $images = \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Image === $mark->kind));

        $this->assertCount(3, $images);
        $this->assertStringStartsWith('data:image/png;base64,', $images[0]->text, 'the bytes, never the path');
        $this->assertEqualsWithDelta(75 * Units::POINT, $images[0]->points[1][0] - $images[0]->points[0][0], 1e-9, 'at its own size');
        $graph = Work::run(new DotParser()->parse('digraph { a [image="red.png", shape=box]; b [image="red.png"] }'));
        $graph->images = ['red.png' => new DotImages([$this->root.'/allowed'])->read('red.png') ?? throw new \LogicException('red.png reads')];
        $this->assertSame([77.0, 39.0], DotNodes::size($graph, $graph->nodes['a']), 'a box as large as its image and a point either side, as dot sizes it');
        $this->assertEqualsWithDelta([77 * \M_SQRT2, 39 * \M_SQRT2], DotNodes::size($graph, $graph->nodes['b']), 1e-9, 'an ellipse round that box');

        foreach (['digraph { a [image="../outside/secret.png"] }', 'digraph { a [label=<<TABLE><TR><TD><IMG SRC="nowhere.png"/></TD></TR></TABLE>>] }'] as $source) {
            try {
                Work::run($pipeline->drawing($source));
                $this->fail($source);
            } catch (Unsupported $refusal) {
                $this->assertStringContainsString('images it may not read', $refusal->getMessage());
            }
        }
        $this->expectException(Unsupported::class);
        Work::run(new DotPipeline()->drawing('digraph { a [image="red.png"] }'));
    }

    private static function png(string $path, int $width, int $height): void
    {
        $image = \imagecreatetruecolor($width, $height);
        \imagepng($image, $path);
    }
}
