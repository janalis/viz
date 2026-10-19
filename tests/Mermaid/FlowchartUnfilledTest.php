<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Janalis\Viz\Mermaid\MermaidCss;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Mermaid\MermaidSyntaxError;

final class FlowchartUnfilledTest extends TestCase
{
    public function testNoneIsPaintSyntaxRatherThanATextColor(): void
    {
        $statement = new MermaidStatement('style A color:none', offset: 12, line: 2, column: 0);
        $css = new MermaidCss('flowchart');
        $this->assertSame(['stroke' => 'none', 'fill' => 'none'], $css->parse('stroke:NONE,fill:none', $statement));
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Invalid flowchart style color');
        $css->parse('color:none', $statement);
    }
}
