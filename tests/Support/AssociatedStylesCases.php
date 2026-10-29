<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Support;

use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\Model\AssociatedFile;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;

final readonly class AssociatedStylesCases
{
    /** @return array<string, array{html:string, originalStyles:string, selectors:array<string,list<string>>, expected:array<string,array<string,string>>}> */
    public function all(): array
    {
        $policy = new DerivationPolicy(allowEmbeddedStylesheets: true, mergeCatalogHtmlSupplements: true);
        $path = dirname(__DIR__).'/Fixtures/mathml-latex/latex-layout.pdf';
        $bytes = file_get_contents($path);
        if ($bytes === false || hash('sha256', $bytes) !== 'd09301d51d14eaf6a677e87f93d329dad720c66e2c9e61779ab47ad6793e8913') {
            throw new \RuntimeException('The original LaTeX regression PDF changed.');
        }
        $source = (new ReadPdfSource(policy: $policy))->read((new DocumentReader())->read(new FileBytes($bytes)), 'latex-layout.pdf');
        $styles = implode('', array_map(static fn (AssociatedFile $file): string => $file->bytes ?? '', $source->associatedFiles));
        $control = new TaggedDocument('css-conditions.pdf', [new StructureElement('control', 'P', [new TextContent('CSS condition and browser fallback control')], properties: new StructureProperties(id: 'css-proof')),
            new StructureElement('author-list', 'L', [new StructureElement('author-item', 'LI', [
                new StructureElement('author-label', 'Lbl', [new TextContent('1.')]),
                new StructureElement('author-body', 'LBody', [new StructureElement('author-first', 'P', [new TextContent('Author styles override list layout defaults.')], properties: new StructureProperties(id: 'author-first'))]),
            ], properties: new StructureProperties(id: 'author-item'))]),
        ], associatedFiles: [
            new AssociatedFile('first.html', 'text/html', 'Supplement', '<style>#css-proof{text-align:right;text-align:-moz-right;text-align:-webkit-right}</style>'),
            new AssociatedFile('second.html', 'text/html', 'Supplement', '<style>@supports (display:block){@media screen and (min-width:1px){#css-proof{border-top:7px solid rgb(1,2,3);color:rgb(4,5,6)}}}#author-item{display:block}#author-first{margin-top:11px}</style>'),
        ]);
        return [
            'latex-catalogue-styles' => [
                'html' => (new HtmlDeriver($policy))->derive($source)->html,
                'originalStyles' => $styles,
                'selectors' => ['li > span[data-pdf-se-type="Lbl"]' => ['display', 'margin-right', 'vertical-align'], 'mtable.align > mtr > mtd:nth-child(2n)' => ['text-align', 'padding-right', 'padding-left']],
                'expected' => [],
            ],
            'nested-conditions' => [
                'html' => (new HtmlDeriver($policy))->derive($control)->html,
                'originalStyles' => implode('', array_map(static fn (AssociatedFile $file): string => $file->bytes ?? '', $control->associatedFiles)),
                'selectors' => ['#css-proof' => ['text-align', 'border-top-width', 'border-top-color', 'color'], '#author-item' => ['display'], '#author-first' => ['margin-top']],
                'expected' => ['#css-proof' => ['border-top-width' => '7px', 'border-top-color' => 'rgb(1, 2, 3)', 'color' => 'rgb(4, 5, 6)'], '#author-item' => ['display' => 'block'], '#author-first' => ['margin-top' => '11px']],
            ],
        ];
    }
}
