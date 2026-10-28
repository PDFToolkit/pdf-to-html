<?php

declare(strict_types=1);

namespace PDF\Html\Signatures;

use PDF\Document\Signatures\SignatureByteRange;
use PDF\Document\Signatures\SignatureContentsValue;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureVerificationInput;
use PDF\Reading\ByteCursor;
use PDF\Reading\SyntaxParser;
use PDF\Security\CertificateChain;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Extract bounded signed bytes and require the omitted range to be exactly Contents. */
final readonly class SignatureVerificationSource
{
    public function __construct(private \PDF\Reading\RevisionHistoryReader $history = new \PDF\Reading\RevisionHistoryReader())
    {
    }

    public function input(SignatureValidationRequest $request, CertificateChain $certificate): SignatureVerificationInput
    {
        $resolve = static fn (?BasicType $value): ?BasicType => $value === null ? null : $request->document->resolve($value);
        $subFilter = $resolve($request->signature->get('SubFilter'));
        $format = $subFilter instanceof NameType ? SignatureFormat::tryFrom($subFilter->value) : null;
        if ($format === null || $format === SignatureFormat::Rfc3161) {
            throw new \LogicException('This signature format requires an explicit PDF signature validation provider.');
        }
        $ranges = $resolve($request->signature->get('ByteRange'));
        $contents = $resolve($request->signature->get('Contents'));
        if (!$ranges instanceof ArrayType || count($ranges) !== 4 || !$contents instanceof StringType) {
            throw new \InvalidArgumentException('A signature requires Contents and two complete byte ranges.');
        }
        $numbers = [];
        foreach ($ranges as $range) {
            $range = $resolve($range);
            if (!$range instanceof IntegerType || $range->integer() < 0) {
                throw new \InvalidArgumentException('Signature byte-range offsets and lengths must be nonnegative integers.');
            }
            $numbers[] = $range->integer();
        }
        if ($numbers[0] !== 0) {
            throw new \InvalidArgumentException('The signature must cover the beginning of its PDF revision.');
        }
        $range = new SignatureByteRange($numbers[1], $numbers[2], $numbers[3]);
        $revision = $request->document->sourceRevision();
        $bytes = $revision->source->bytes;
        $span = $revision->span($contents);
        if ($span === null) {
            throw new \LogicException('Signature Contents has no retained physical provenance; provide an explicit validation provider.');
        }
        if ($span->start !== $range->before || $span->end !== $range->afterOffset) {
            throw new \InvalidArgumentException('The omitted signature range must match this exact physical Contents token.');
        }
        $completeRevision = false;
        foreach ($this->history->read($revision->source) as $physical) {
            if ($physical->acceptsByteLength($range->afterOffset + $range->afterLength)) {
                $completeRevision = true;
                break;
            }
        }
        if (!$completeRevision) {
            throw new \InvalidArgumentException('The signed byte ranges must end at a complete physical PDF revision.');
        }
        $covered = $range->coveredBytes($bytes);
        $cursor = new ByteCursor($bytes, $range->before, $range->afterOffset);
        if (!in_array($cursor->peek(), ['<', '('], true)) {
            throw new \InvalidArgumentException('The omitted signature range must start at its Contents token.');
        }
        $omitted = (new SyntaxParser($cursor))->read();
        if (!$omitted instanceof StringType || $cursor->remaining() !== 0 || !hash_equals($contents->value, $omitted->value)) {
            throw new \InvalidArgumentException('The omitted signature range must contain only the exact Contents string.');
        }
        return new SignatureVerificationInput($covered, (new SignatureContentsValue($contents))->value, $format, $certificate);
    }
}
