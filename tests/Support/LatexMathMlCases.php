<?php

declare(strict_types=1);

namespace PDF\Html\Tests\Support;

use PDF\Html\PdfToHtml;
use PDF\Reading\FileBytes;

/** Browser witnesses from retained LaTeX PDFs, not reconstructed semantic models. */
final readonly class LatexMathMlCases
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $directory = dirname(__DIR__) . '/Fixtures/mathml-latex/';
        $provenanceBytes = file_get_contents($directory . 'provenance.json');
        if ($provenanceBytes === false) {
            throw new \RuntimeException('The retained LaTeX provenance is required.');
        }
        $provenance = json_decode($provenanceBytes, true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach (['mathml-se', 'mathml-se-af'] as $name) {
            $bytes = file_get_contents($directory . $name . '.pdf');
            $fixture = $provenance['fixtures'][$name];
            if ($bytes === false || !hash_equals($fixture['pdf']['sha256'], hash('sha256', $bytes))) {
                throw new \RuntimeException('The retained LaTeX PDF must match its generation provenance.');
            }
            $cases['latex-' . $name] = [
                'html' => (new PdfToHtml())->convert(new FileBytes($bytes), $name . '.pdf')->html,
                'source' => ['kind' => 'latex-pdf', 'path' => 'tests/Fixtures/mathml-latex/' . $name . '.pdf', 'sha256' => $fixture['pdf']['sha256'], 'mode' => $fixture['mode']],
                'expectedMath' => [
                    ['id' => 'ID.016', 'display' => 'inline', 'text' => '𝑥2+12', 'superscripts' => 1, 'fractions' => 1, 'matrices' => []],
                    ['id' => 'ID.030', 'display' => 'block', 'text' => '𝐸=𝑚𝑐2+12', 'superscripts' => 1, 'fractions' => 1, 'matrices' => []],
                    ['id' => 'ID.055', 'display' => 'block', 'text' => '(1234)', 'superscripts' => 0, 'fractions' => 0, 'matrices' => [['rows' => 2, 'columns' => 2, 'cells' => [['1', '2'], ['3', '4']]]]],
                ],
                'htmlDisplays' => (object) [],
            ];
        }
        return $cases;
    }
}
