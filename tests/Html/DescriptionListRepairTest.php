<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Html;

use PDF\Html\Html\ContentModelRepair;
use PDF\Html\Html\Element;
use PDF\Html\Html\HtmlNode;
use PDF\Html\Html\Serializer;
use PDF\Html\Html\Text;
use PHPUnit\Framework\TestCase;

final class DescriptionListRepairTest extends TestCase
{
    public function testOrphanListSplitsDescriptionGroupsInReadingOrderWithoutDuplicateIds(): void
    {
        $before = new Element('div', [], [new Element('dt', [], [new Text('Term before')]), new Element('dd', [], [new Text('Definition before')])]);
        $orphan = new Element('ol', ['id' => 'nested'], [new Element('li', [], [new Text('Nested list')])]);
        $after = new Element('div', [], [new Element('dt', [], [new Text('Term after')]), new Element('dd', [], [new Text('Definition after')])]);
        $repair = new ContentModelRepair();
        $output = $repair->repair(new Element('dl', ['id' => 'description', 'class' => 'terms'], [$before, $orphan, $after]));

        $first = $output[0];
        $last = $output[2];
        self::assertInstanceOf(Element::class, $first);
        self::assertInstanceOf(Element::class, $last);
        self::assertSame(['dl', 'ol', 'dl'], array_map(static fn (HtmlNode $node): string => $node instanceof Element ? $node->tag : '#text', $output));
        self::assertSame([$before], $first->children);
        self::assertSame($orphan, $output[1]);
        self::assertSame([$after], $last->children);
        self::assertSame('description', $first->attributes['id']);
        self::assertArrayNotHasKey('id', $last->attributes);
        self::assertSame('terms', $last->attributes['class']);
        self::assertNotEmpty($repair->diagnostics);
    }

    public function testNestedListInAnExistingDefinitionRetainsItsTermAssociation(): void
    {
        $list = new Element('ul', [], [new Element('li', [], [new Text('Detail')])]);
        $definition = new Element('dd', [], [$list]);
        $source = new Element('dl', [], [new Element('dt', [], [new Text('Term')]), $definition]);
        $repair = new ContentModelRepair();

        self::assertSame([$source], $repair->repair($source));
        self::assertSame([$list], $definition->children);
        self::assertSame([], $repair->diagnostics);
    }

    public function testOnlyOrphanListRetainsDestinationWithoutInventingATerm(): void
    {
        $list = new Element('ul', [], [new Element('li', [], [new Text('Orphan')])]);
        $output = (new ContentModelRepair())->repair(new Element('dl', ['id' => 'destination'], [$list]));

        self::assertCount(2, $output);
        self::assertSame('<dl id="destination"></dl>', (new Serializer())->serialize($output[0]));
        self::assertSame($list, $output[1]);
    }

    public function testNestedOrphanDescriptionListsKeepAllAnchorsOutsideParentLists(): void
    {
        $list = new Element('ul', [], [new Element('li', [], [new Text('Nested')])]);
        $inner = new Element('dl', ['id' => 'inner'], [$list]);
        $output = (new ContentModelRepair())->repair(new Element('dl', ['id' => 'outer'], [$inner]));

        self::assertCount(3, $output);
        self::assertSame('<dl id="outer"></dl>', (new Serializer())->serialize($output[0]));
        self::assertSame('<dl id="inner"></dl>', (new Serializer())->serialize($output[1]));
        self::assertSame($list, $output[2]);
    }
}
