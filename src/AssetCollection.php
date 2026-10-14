<?php

declare(strict_types=1);

namespace PDF\Html;

/** Document-scoped byte ownership; writing files is the caller's responsibility. */
final class AssetCollection
{
    /** @var list<Asset> */
    private array $assets = [];
    private int $bytes = 0;

    public function __construct(private readonly DerivationLimits $limits)
    {
    }

    public function add(string $filename, string $type, string $bytes): string
    {
        $this->bytes += strlen($bytes);
        if ($this->bytes > $this->limits->maximumAssetBytes) {
            throw new DerivationException('The cumulative derived asset limit was exceeded.');
        }
        $candidate = new Asset($filename, $type, $bytes);
        foreach ($this->assets as $asset) {
            if ($asset->filename === $filename) {
                if ($asset->bytes !== $bytes || $asset->mediaType !== $type) {
                    throw new DerivationException('Two different associated files use the same output filename.');
                }
                return $filename;
            }
        }
        $this->assets[] = $candidate;
        return $filename;
    }

    /**
     * @return list<Asset>
     */
    public function all(): array
    {
        return $this->assets;
    }
}
