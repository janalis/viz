<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid\Source;

use Janalis\Viz\Mermaid\MermaidReader;
use Janalis\Viz\Mermaid\Source\MermaidEntityReader;
use Janalis\Viz\Mermaid\Source\MermaidEntityWriter;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Entity\Attribute;
use Janalis\Viz\Model\Entity\Cardinality;
use Janalis\Viz\Model\Entity\EntityDiagram;
use Janalis\Viz\Model\Entity\Key;
use Janalis\Viz\Tests\Support\EntitySample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MermaidEntityTest extends TestCase
{
    public function testAnEntityRelationshipDiagramIsReadIntoTheSharedModel(): void
    {
        $diagram = new MermaidReader()->read(<<<'MMD'
            ---
            title: Shop
            ---
            erDiagram
              direction LR
              subgraph sales["Sales"]
                ORDER {
                  int number PK,FK "the number"
                }
              end
              CUSTOMER["Client"] {
                string id PK
              }
              CUSTOMER ||--o{ ORDER : places
              ORDER |o..|{ ITEM : "contains"
              ITEM }|--o| PRODUCT : has
              PRODUCT u--|| PRODUCT : self
            MMD);

        $this->assertInstanceOf(EntityDiagram::class, $diagram);
        $this->assertSame([Direction::LeftRight, 'Shop'], [$diagram->direction, $diagram->title]);
        $this->assertEquals([new Attribute('int', 'number', [Key::Primary, Key::Foreign], 'the number')], $diagram->entities[0]->attributes);
        $this->assertSame(['CUSTOMER', 'Client'], [$diagram->entities[1]->id, $diagram->entities[1]->label]);
        $this->assertNull($diagram->entities[0]->label);
        $relationships = $diagram->relationships;
        $this->assertSame([Cardinality::ExactlyOne, Cardinality::ZeroOrMore, true, 'places'], [
            $relationships[0]->fromCardinality,
            $relationships[0]->toCardinality,
            $relationships[0]->identifying,
            $relationships[0]->label,
        ]);
        $this->assertSame([Cardinality::ZeroOrOne, Cardinality::OneOrMore, false, 'contains'], [
            $relationships[1]->fromCardinality,
            $relationships[1]->toCardinality,
            $relationships[1]->identifying,
            $relationships[1]->label,
        ]);
        $this->assertSame([Cardinality::OneOrMore, Cardinality::ZeroOrOne], [$relationships[2]->fromCardinality, $relationships[2]->toCardinality]);
        $this->assertSame(Cardinality::ExactlyOne, $relationships[3]->fromCardinality, 'Mermaid\'s u reads as exactly one');
        $this->assertSame(['sales', 'Sales', ['ORDER']], [$diagram->groups[0]->id, $diagram->groups[0]->label, $diagram->groups[0]->entities]);
    }

    public function testTheSharedModelIsWrittenAsAnEntityRelationshipDiagram(): void
    {
        $this->assertSame(<<<'MMD'
            ---
            title: "Shop"
            ---
            erDiagram
                direction LR
                subgraph sales["Sales"]
                    subgraph items
                        "LINE ITEM"
                    end
                    ORDER {
                        int number PK,FK,UK
                    }
                end
                CUSTOMER["Client #quot;VIP#quot;"] {
                    string id PK
                    string `full name` "legal<br>name"
                }
                PRODUCT
                CUSTOMER ||--o{ ORDER : "places"
                ORDER |o..|{ "LINE ITEM" : "contains<br>lines"
                PRODUCT }|--o| "LINE ITEM" : ""
                PRODUCT }o--|| PRODUCT : " "

            MMD, MermaidEntityWriter::write(EntitySample::diagram()));
    }

    #[DataProvider('corpus')]
    public function testWritingWhatWasReadReachesAFixedPoint(string $file): void
    {
        $first = MermaidEntityWriter::write(MermaidEntityReader::read((string) \file_get_contents($file)));

        $this->assertSame($first, MermaidEntityWriter::write(MermaidEntityReader::read($first)));
    }

    /** @return iterable<string, array{string}> */
    public static function corpus(): iterable
    {
        $files = \glob(__DIR__.'/../../fixtures/mermaid/{er,upstream/er-diagram}/*.mmd', \GLOB_BRACE);
        foreach (false === $files ? [] : $files as $file) {
            yield \basename(\dirname($file)).'/'.\basename($file) => [$file];
        }
    }
}
