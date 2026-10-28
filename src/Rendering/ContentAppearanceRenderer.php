<?php

declare(strict_types=1);

namespace PDF\Html\Rendering;

use PDF\Html\AssetCollection;
use PDF\Html\ContentRenderer;
use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\Forms\AppearanceRenderer;
use PDF\Html\Html\Element;
use PDF\Html\Html\HtmlNode;
use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\SourceNode;

/** Render one complete appearance through the caller's existing graphics engine, without I/O. */
final readonly class ContentAppearanceRenderer implements AppearanceRenderer
{
    public function __construct(
        private ContentRenderer $renderer,
        private DerivationLimits $limits = new DerivationLimits(),
        private ?AssetCollection $assets = null,
    ) {
    }

    /**
     * @param list<SourceNode> $appearance
     * @return list<HtmlNode>
     */
    public function render(array $appearance): array
    {
        if ($appearance === []) {
            return [];
        }
        if (count($appearance) > $this->limits->maximumNodes) {
            throw new DerivationException('The appearance exceeds its source node limit.');
        }
        // A single group keeps the original text, paths, images, clipping and compositing together.
        $image = $this->renderer->render(new ContentGroup($appearance));
        if ($image === null) {
            throw new DerivationException('The supplied content renderer could not render the widget appearance.');
        }
        $dimensions = [];
        foreach ([$image->widthPoints, $image->heightPoints] as $points) {
            $pixels = round($points * 4 / 3);
            if (!is_finite($points) || $points < 0 || !is_finite($pixels) || $pixels >= PHP_INT_MAX) {
                throw new DerivationException('Appearance logical dimensions must be finite and representable as HTML pixels.');
            }
            $dimensions[] = (string) max(1, (int) $pixels);
        }
        $bytes = $image->bytes;
        if ($bytes === null || !in_array($image->mediaType, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            throw new DerivationException('The appearance renderer must return portable raster image bytes.');
        }
        if (strlen($bytes) > $this->limits->maximumAssetBytes) {
            throw new DerivationException('The rendered appearance exceeds its asset byte limit.');
        }
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || $info['mime'] !== $image->mediaType) {
            throw new DerivationException('The rendered appearance bytes do not match their declared image type.');
        }
        $prefix = 'data:' . $image->mediaType . ';base64,';
        if (strlen($prefix) + 4 * ceil(strlen($bytes) / 3) > $this->limits->maximumOutputBytes) {
            throw new DerivationException('The encoded appearance exceeds its output byte limit.');
        }
        $extension = match ($image->mediaType) {
            'image/png' => '.png',
            'image/jpeg' => '.jpg',
            default => '.gif',
        };
        $this->assets?->add('image-' . hash('sha256', $bytes) . $extension, $image->mediaType, $bytes);
        return [new Element('img', [
            'src' => $prefix . base64_encode($bytes),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'alt' => '',
        ])];
    }
}
