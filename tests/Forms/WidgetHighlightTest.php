<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Forms;

use PDF\Html\DerivationException;
use PDF\Html\Forms\FieldType;
use PDF\Html\Forms\HighlightingMode;
use PDF\Html\Forms\WidgetDeriver;
use PDF\Html\Html\Element;
use PDF\Html\Html\SafetyFilter;
use PDF\Html\DerivationPolicy;
use PDF\Html\DerivationLimits;
use PDF\Html\Model\FormField;
use PDF\Html\Model\TextContent;
use PDF\Html\Model\Widget;
use PDF\Html\Tests\Support\WidgetHighlightCases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WidgetHighlightTest extends TestCase
{
    public function testAllPdfModesProduceSafeEffectiveCssIncludingDefaultInvert(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(), new DerivationLimits());
        foreach ([null, 'N', 'I', 'O', 'P', 'T'] as $mode) {
            $rendering = (new WidgetDeriver())->derive($this->widget($mode), 'an-id"]{color:red}');
            self::assertSame($mode ?? 'I', $rendering->field->attributes['data-pdf-highlight']);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $rendering->field->attributes['data-pdf-widget-style']);
            self::assertStringContainsString(':active)', $rendering->css);
            self::assertStringNotContainsString('an-id', $rendering->css);
            self::assertNotSame('', $filter->stylesheet($rendering->css));
            self::assertSame([], $filter->diagnostics);
            self::assertSame([], $rendering->diagnostics);
        }
        self::assertSame(HighlightingMode::Invert, HighlightingMode::fromPdf(null));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidModes(): iterable
    {
        foreach (['', 'i', 'Invert', 'N;filter:url(https://example.test/)', "P\0"] as $mode) {
            yield bin2hex($mode) => [$mode];
        }
    }

    #[DataProvider('invalidModes')]
    public function testInvalidHighlightingIsRejectedBeforeInterpolation(string $mode): void
    {
        $this->expectException(DerivationException::class);
        (new WidgetDeriver())->derive($this->widget($mode), 'control');
    }

    public function testDownAppearanceOnlyOverridesNormalForPushAndToggle(): void
    {
        foreach (['N', 'I', 'O', 'P', 'T'] as $mode) {
            $widget = new Widget(new FormField('button', FieldType::Button, 65536), page: 1, rectangle: [0, 0, 100, 30], caption: 'Caption', appearance: [new TextContent('Normal')], highlighting: $mode, downAppearance: [new TextContent('Pressed')], rolloverAppearance: [new TextContent('Hover')]);
            $rendering = (new WidgetDeriver(new WidgetHighlightCases()))->derive($widget, 'button');
            $wrapper = $rendering->field->children[0];
            self::assertInstanceOf(Element::class, $wrapper);
            $states = array_map(static fn (Element $element): string => $element->attributes['data-pdf-widget-appearance'], $wrapper->children);
            self::assertSame(in_array($mode, ['P', 'T'], true) ? ['normal', 'rollover', 'down'] : ['normal', 'rollover'], $states);
            self::assertStringNotContainsString('display:none', $rendering->css);
            self::assertSame('Caption', $wrapper->children[0]->children[1]->value);
            self::assertSame('true', $wrapper->children[1]->attributes['aria-hidden']);
        }
    }

    public function testMissingAppearanceRendererUsesPushFallbackWithDiagnostic(): void
    {
        $widget = new Widget(new FormField('button', FieldType::Button, 65536), page: 1, rectangle: [0, 0, 100, 30], highlighting: 'P', downAppearance: [new TextContent('Pressed')]);
        $rendering = (new WidgetDeriver())->derive($widget, 'button');
        self::assertStringContainsString('translate:1px 1px', $rendering->css);
        self::assertStringContainsString('AppearanceRenderer', implode(' ', $rendering->diagnostics));
    }

    public function testActualDerivationKeepsWidgetCssAfterSafetyAndAuthorIdentityOverrides(): void
    {
        $cases = (new WidgetHighlightCases())->all();
        foreach ($cases as $name => $case) {
            self::assertStringContainsString(':active)', $case['html'], $name);
            self::assertStringNotContainsString('<script', $case['html'], $name);
        }
        self::assertStringContainsString('id="author-control"', $cases['source-css-and-id']['html']);
        self::assertStringContainsString('filter:sepia(1)', $cases['source-css-and-id']['html']);
    }

    private function widget(?string $mode): Widget
    {
        return new Widget(new FormField('button', FieldType::Button, 65536), page: 1, rectangle: [0, 0, 100, 30], highlighting: $mode);
    }
}
