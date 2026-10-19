<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid\Source;

use Janalis\Viz\Mermaid\MermaidReader;
use Janalis\Viz\Mermaid\Source\MermaidUseCaseReader;
use Janalis\Viz\Mermaid\Source\MermaidUseCaseWriter;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\UseCase\AssociationKind;
use Janalis\Viz\Model\UseCase\UseCaseDiagram;
use Janalis\Viz\Tests\Support\UseCaseSample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MermaidUseCaseTest extends TestCase
{
    public function testAUseCaseDiagramIsReadIntoTheSharedModel(): void
    {
        $diagram = new MermaidReader()->read(<<<'MMD'
            usecase-beta
              direction TB
              actor Sales("Sales agent")@{ business: true } <<Employee>>
              actor Boss
              systemBoundary Shop["Web shop"]
                Quote("Prepare quote") <<Core>>
              end
              Sales --> Quote
              Quote <-- Sales
              Sales -- "helps" --> Quote
              Sales o-- Quote
              Sales --x Quote
              Sales --|> Boss
              Quote ..> : include Other
              note for Quote "check"
            MMD);

        $this->assertInstanceOf(UseCaseDiagram::class, $diagram);
        $this->assertSame(Direction::TopBottom, $diagram->direction);
        $this->assertSame(['Sales', 'Sales agent', 'Employee', true], [$diagram->actors[0]->id, $diagram->actors[0]->label, $diagram->actors[0]->stereotype, $diagram->actors[0]->business]);
        $this->assertSame(['Quote', 'Prepare quote', 'Core'], [$diagram->useCases[0]->id, $diagram->useCases[0]->label, $diagram->useCases[0]->stereotype]);
        $this->assertSame('Other', $diagram->useCases[1]->id, 'an endpoint declared nowhere stands as a use case');
        $this->assertSame(['Shop', 'Web shop', ['Quote']], [$diagram->boundaries[0]->id, $diagram->boundaries[0]->label, $diagram->boundaries[0]->members]);
        $marks = \array_map(static fn ($association): array => [$association->kind, $association->head, $association->tail, $association->label], $diagram->associations);
        $this->assertSame(
            [
                [AssociationKind::Association,    ArrowHead::Arrow, ArrowHead::None,   ''],
                [AssociationKind::Association,    ArrowHead::None,  ArrowHead::Arrow,  ''],
                [AssociationKind::Association,    ArrowHead::Arrow, ArrowHead::None,   'helps'],
                [AssociationKind::Association,    ArrowHead::None,  ArrowHead::Circle, ''],
                [AssociationKind::Association,    ArrowHead::Cross, ArrowHead::None,   ''],
                [AssociationKind::Generalization, ArrowHead::Arrow, ArrowHead::None,   ''],
                [AssociationKind::Include,        ArrowHead::Arrow, ArrowHead::None,   ''],
            ],
            $marks,
        );
        $this->assertSame(['check', 'Quote'], [$diagram->notes[0]->text, $diagram->notes[0]->for]);
    }

    public function testTheSharedModelIsWrittenAsAUseCaseDiagram(): void
    {
        $this->assertSame(<<<'MMD'
            ---
            title: "Shop"
            ---
            usecase-beta
                direction TB
                systemBoundary Shop["Web shop"]
                    Browse("Browse catalog")
                    Pay <<Core>>
                    actor Clerk("Sales #quot;clerk#quot;")@{ business: true } <<Employee>>
                end
                actor Customer
                actor u0("end")
                Coupon@{ business: true }
                Audit
                Customer --> Browse
                Customer -- "pays<br>online" --> Pay
                Clerk <-- "helps" -- Pay
                Clerk <-- Audit
                Customer --o Audit
                u0 x-- "x" -- Audit
                Clerk -- Customer
                Clerk --> Coupon
                Pay ..> : include Audit
                Coupon ..> : extend Pay
                Clerk --|> Customer
                Clerk -- "«include»" --> Pay
                Clerk -- "«generalization»" --> Pay
                note for Pay "Needs<br>login"

            MMD, MermaidUseCaseWriter::write(UseCaseSample::diagram()));
    }

    #[DataProvider('corpus')]
    public function testWritingWhatWasReadReachesAFixedPoint(string $file): void
    {
        $first = MermaidUseCaseWriter::write(MermaidUseCaseReader::read((string) \file_get_contents($file)));

        $this->assertSame($first, MermaidUseCaseWriter::write(MermaidUseCaseReader::read($first)));
    }

    /** @return iterable<string, array{string}> */
    public static function corpus(): iterable
    {
        $files = \glob(__DIR__.'/../../fixtures/mermaid/usecase/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [$file];
        }
    }
}
