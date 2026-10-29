<?php

declare(strict_types=1);

namespace PDF\Html;

use PDF\Html\Html\SafetyFilter;
use PDF\Html\Model\AssociatedFile;

/** One policy decision shared by native source interpretation and HTML derivation. */
final readonly class AssociatedFileSelection
{
    public function __construct(public DerivationPolicy $policy = new DerivationPolicy(), public DerivationLimits $limits = new DerivationLimits())
    {
    }

    public function accepts(AssociatedFile $file): bool
    {
        if ($file->url !== null) {
            $scheme = parse_url($file->url, PHP_URL_SCHEME);
            if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true) || (new SafetyFilter($this->policy, $this->limits))->url($file->url, true) === null) {
                return false;
            }
        }
        return match (strtolower($file->mediaType)) {
            'text/html', 'application/xhtml+xml' => $file->url === null || $this->policy->allowActiveContent,
            'image/jpeg', 'image/png', 'image/gif' => true,
            'application/mathml+xml' => $file->url === null,
            'text/javascript', 'application/javascript' => $this->policy->allowActiveContent,
            'image/svg+xml' => $file->url !== null || $this->policy->allowActiveContent,
            'text/css' => $file->url !== null
                ? $this->policy->allowRemoteResources
                : $this->policy->allowEmbeddedStylesheets || $this->policy->allowActiveContent,
            default => false,
        };
    }

    public function replaces(AssociatedFile $file): bool
    {
        return $file->relationship === 'Alternative' && $this->accepts($file);
    }

    public function supplements(AssociatedFile $file): bool
    {
        return $file->relationship === 'Supplement' && $this->accepts($file);
    }
}
