<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Html;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\DerivationPolicy;
use PDF\Html\Html\CssSafetyFilter;
use PDF\Html\Html\SafetyFilter;
use PHPUnit\Framework\TestCase;

final class CssSafetyFilterTest extends TestCase
{
    public function testOrderedFallbacksSurviveRulesAndStyleAttributes(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(allowEmbeddedStylesheets: true), new DerivationLimits());
        $css = 'text-align:right;text-align:-moz-right;text-align:-webkit-right';
        self::assertSame('mtd{'.$css.'}', $filter->stylesheet('mtd{'.$css.'}'));
        $nodes = $filter->fragment('<math><mtd style="'.$css.'">x</mtd></math>');
        self::assertSame($css, $nodes[0]->children[0]->attributes['style']);
    }

    public function testDeclarationObjectsAlsoConsumeTheCumulativeByteBudget(): void
    {
        $filter = new CssSafetyFilter(new DerivationLimits(maximumOutputBytes: 24));
        self::assertSame(['color' => 'red'], $filter->declarations(['color' => 'red']));
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('cumulative byte');
        $filter->declarations(['color' => str_repeat('x', 20)]);
    }

    public function testHtmlStyleFallbacksSurviveUntilExplicitPdfCssOverridesThem(): void
    {
        $style = 'text-align:right;text-align:-moz-right;text-align:-webkit-right;color:red!important;content:"first;second";--Case:red;--case:blue';
        $filter = new CssSafetyFilter(new DerivationLimits());
        self::assertSame($style.';margin:0', $filter->mergeDeclarations($style, ['margin' => '0']));
        self::assertSame('content:"first;second";--case:blue;text-align:center;COLOR:green;--Case:yellow', $filter->mergeDeclarations($style, ['text-align' => 'center', 'COLOR' => 'green', '--Case' => 'yellow']));
    }

    public function testNestedConditionsAndFollowingRulesArePreserved(): void
    {
        $filter = new CssSafetyFilter(new DerivationLimits());
        $sheet = '@supports (-webkit-appearance:none) and (stroke-color:transparent) {'
            .'@media screen and (min-width:1px) { mtd:nth-child(2n+1) { padding-right:0; float:right; display:math; } }'
            .'} mtable { width:100%; }';
        self::assertSame('@supports (-webkit-appearance:none) and (stroke-color:transparent){@media screen and (min-width:1px){mtd:nth-child(2n+1){padding-right:0;float:right;display:math}}}mtable{width:100%}', $filter->stylesheet($sheet));
        self::assertSame([], $filter->diagnostics);
    }

    public function testEmbeddedStyleCapabilityNeverEnablesScriptOrRemoteResources(): void
    {
        $filter = new SafetyFilter(new DerivationPolicy(allowEmbeddedStylesheets: true), new DerivationLimits());
        $nodes = $filter->fragment('<style>p{color:red;background:url(https://evil.test/pixel)}</style><script>alert(1)</script><link rel="stylesheet" href="https://evil.test/sheet.css">', head: true);
        self::assertCount(1, $nodes);
        self::assertSame('style', $nodes[0]->tag);
        self::assertSame('p{color:red}', $nodes[0]->children[0]->value);
        self::assertCount(3, $filter->diagnostics);
        $body = $filter->fragment('<p onclick="alert(1)">Text</p><script>alert(2)</script>');
        self::assertCount(1, $body);
        self::assertArrayNotHasKey('onclick', $body[0]->attributes);
    }

    public function testUnsafeStatementsAndNestedRulesCannotFetchResources(): void
    {
        $filter = new CssSafetyFilter(new DerivationLimits());
        $sheet = '@import "https://evil.test/one"; @font-face{font-family:bad;src:url(https://evil.test/two)}'
            .'@supports (display:grid) { p { color:red; background:u/**/rl(https://evil.test/three); mask:image-set("https://evil.test/four" 1x); behavior:bad; -moz-binding:bad; } }'
            .'@unknown a { p { color:blue; } } span {color:green}';
        self::assertSame('@supports (display:grid){p{color:red}}span{color:green}', $filter->stylesheet($sheet));
        self::assertNotEmpty($filter->diagnostics);
    }

    public function testQuotedDelimitersAreNotParsedAsRulesOrDeclarations(): void
    {
        $filter = new CssSafetyFilter(new DerivationLimits());
        self::assertSame('p{content:"first;second";color:red}span{color:green}', $filter->stylesheet('p{content:"first;second";color:red}span{color:green}'));
        self::assertSame('p{color:red}', $filter->stylesheet('p{content:"};p{color:blue}";color:red}'));
        self::assertSame('', $filter->stylesheet('p{content:"unterminated}'));
    }

    public function testEscapesAndResourceFunctionsAreRejected(): void
    {
        $filter = new CssSafetyFilter(new DerivationLimits());
        self::assertSame('', $filter->stylesheet('p{background:u\\72l(https://evil.test/x)}'));
        self::assertSame('p{color:red}', $filter->stylesheet('p{background:image("https://evil.test/x");color:red}'));
    }

    public function testMalformedDeclarationDoesNotDiscardFollowingValidDeclaration(): void
    {
        $filter = new CssSafetyFilter(new DerivationLimits());
        self::assertSame('mtd{padding-left:1em}', $filter->stylesheet('mtd{border margin-left:1em;padding-left:1em}'));
        self::assertSame('blocked-css', $filter->diagnostics[0]->code);
    }

    public function testStylesheetByteBudgetIsCumulative(): void
    {
        $filter = new CssSafetyFilter(new DerivationLimits(maximumOutputBytes: 24));
        self::assertSame('p{color:red}', $filter->stylesheet('p{color:red}'));
        self::assertSame('p{color:red}', $filter->stylesheet('p{color:red}'));
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('cumulative byte');
        $filter->stylesheet('p{}');
    }

    public function testAcceptedAndRejectedNestingAreBothBounded(): void
    {
        foreach (['@supports (display:grid)', '@unknown x'] as $rule) {
            try {
                (new CssSafetyFilter(new DerivationLimits(maximumDepth: 2)))->stylesheet(str_repeat($rule.'{', 4).'p{color:red}'.str_repeat('}', 4));
                self::fail('Nested rules must respect the depth budget.');
            } catch (DerivationException $exception) {
                self::assertStringContainsString('nesting budget', $exception->getMessage());
            }
        }
    }

    public function testTokenBudgetIsCumulativeIncludingRejectedRules(): void
    {
        $filter = new CssSafetyFilter(new DerivationLimits(maximumNodes: 1));
        $this->expectException(DerivationException::class);
        $this->expectExceptionMessage('token budget');
        $filter->stylesheet(str_repeat('@import "x";', 9));
    }
}
