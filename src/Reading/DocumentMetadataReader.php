<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Html\AssociatedFileSelection;
use PDF\Html\Model\AssociatedFile;
use PDF\Reading\EncodedStream;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\StringType;

/** Document metadata, embedded resources and declarative action source; no I/O or execution. */
final readonly class DocumentMetadataReader
{
    public function __construct(private SourceValues $values)
    {
    }
    public function title(DictionaryType $catalog): ?string
    {
        $metadata = $this->values->value($catalog, 'Metadata');
        if (!$metadata instanceof EncodedStream) {
            return null;
        }
        $xml = $this->values->document->streamData($metadata);
        $this->values->budget->consume('content', strlen($xml));
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new ReadingException('XMP metadata may not contain a DTD or entity declarations.');
        }
        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new ReadingException('Document XMP metadata is not well-formed XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($document->doctype !== null) {
            throw new ReadingException('XMP metadata may not contain a DTD, including non-UTF-8 XML declarations.');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $titles = $xpath->query('//dc:title/rdf:Alt/rdf:li[@xml:lang="x-default"] | //dc:title[not(*)]');
        if ($titles === false || $titles->length === 0) {
            $titles = $xpath->query('//dc:title/rdf:Alt/rdf:li[1]');
        }
        $title = $titles !== false && $titles->length > 0 ? $titles->item(0) : null;
        $text = $title instanceof \DOMNode ? $title->textContent : null;
        if ($text !== null) {
            $this->values->budget->consume('text', strlen($text));
        }
        return $text;
    }
    /** @return list<AssociatedFile> */
    public function associatedFiles(DictionaryType $owner, ?AssociatedFileSelection $selection = null, bool $catalog = false, bool $decode = true): array
    {
        /** @var list<array{DictionaryType, ?EncodedStream, AssociatedFile}> $descriptors */
        $descriptors = [];
        $files = [];
        $sources = $this->values->items($owner->get('AF'));
        if ($catalog) {
            $supplement = $this->catalogSupplement($sources, $selection);
            if ($supplement !== null) {
                $sources = [$supplement];
            }
        }
        foreach ($sources as $value) {
            $this->values->budget->consume('nodes');
            $file = $this->values->dictionary($value);
            if ($file === null) {
                throw new ReadingException('An associated file must resolve to a file specification dictionary.');
            }
            $filename = $this->values->text($file->get('UF')) ?? $this->values->text($file->get('F')) ?? 'associated-file';
            $relationship = $this->values->name($file->get('AFRelationship')) ?? 'Unspecified';
            $embedded = $this->values->dictionary($file->get('EF'));
            $stream = $embedded === null ? null : ($this->values->value($embedded, 'UF') ?? $this->values->value($embedded, 'F'));
            $mediaType = 'application/octet-stream';
            if ($stream instanceof EncodedStream) {
                $mediaType = $this->values->name($stream->dictionary()->get('Subtype')) ?? $mediaType;
            } elseif ($embedded !== null) {
                throw new ReadingException('An embedded associated file requires an EF stream.');
            }
            // EF takes precedence over FS /URL (4.6.1, physical page 62).
            $url = $embedded === null && $this->values->name($file->get('FS')) === 'URL' ? $this->values->text($file->get('F')) : null;
            if ($url !== null && $embedded === null) {
                $mediaType = $this->urlMediaType($url);
            }
            $descriptors[] = [$file, $stream instanceof EncodedStream ? $stream : null, new AssociatedFile($filename, $mediaType, $relationship, url: $url)];
        }
        $headSupplement = null;
        if ($catalog) {
            foreach ($descriptors as $index => [, , $descriptor]) {
                if ($descriptor->relationship === 'Supplement' && in_array(strtolower($descriptor->mediaType), ['text/html', 'application/xhtml+xml'], true) && ($selection === null || $selection->accepts($descriptor))) {
                    $headSupplement = $index;
                    break;
                }
            }
        }
        foreach ($descriptors as $index => [, $stream, $descriptor]) {
            $applicable = $selection === null || ($catalog ? $headSupplement === null || $headSupplement === $index : in_array($descriptor->relationship, ['Alternative', 'Supplement'], true));
            $bytes = null;
            if ($decode && $stream !== null && $applicable && ($selection === null || $selection->accepts($descriptor))) {
                $bytes = $this->values->document->streamData($stream);
                $this->values->budget->consume('assets', strlen($bytes));
            }
            $files[] = new AssociatedFile($descriptor->filename, $descriptor->mediaType, $descriptor->relationship, $bytes, $descriptor->url);
        }
        return $files;
    }

    /** @param list<BasicType> $sources */
    private function catalogSupplement(array $sources, ?AssociatedFileSelection $selection): ?BasicType
    {
        foreach ($sources as $source) {
            $this->values->budget->consume('nodes');
            $file = $this->values->dictionary($source);
            if ($file === null || $this->values->name($file->get('AFRelationship')) !== 'Supplement') {
                continue;
            }
            $embedded = $this->values->dictionary($file->get('EF'));
            $stream = $embedded === null ? null : ($this->values->value($embedded, 'UF') ?? $this->values->value($embedded, 'F'));
            $url = $embedded === null && $this->values->name($file->get('FS')) === 'URL' ? $this->values->text($file->get('F')) : null;
            $type = $stream instanceof EncodedStream ? $this->values->name($stream->dictionary()->get('Subtype')) : ($url === null ? null : $this->urlMediaType($url));
            if ($type !== null && in_array(strtolower($type), ['text/html', 'application/xhtml+xml'], true) && ($selection === null || $selection->accepts(new AssociatedFile('head-fragment', $type, 'Supplement', url: $url)))) {
                return $source;
            }
        }
        return null;
    }
    /** Deriving HTML 1.2, 4.6.3/Table 9, physical PDF pages 62–63; no resource is fetched. */
    private function urlMediaType(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return 'application/octet-stream';
        }
        $slash = strrpos($path, '/');
        $filename = $slash === false ? $path : substr($path, $slash + 1);
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'htm', 'html' => 'text/html',
            'xhtml' => 'application/xhtml+xml',
            'css' => 'text/css',
            'js' => 'text/javascript',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'xml', 'mathml' => 'application/mathml+xml',
            default => 'application/octet-stream',
        };
    }
    /** @return list<array{BasicType, BasicType}> */
    public function tree(?BasicType $root, string $entries): array
    {
        $seen = [];
        return $this->treeNode($root, $entries, 1, $seen);
    }
    /**
     * @param array<int, true> $seen
     * @return list<array{BasicType, BasicType}>
     */
    private function treeNode(?BasicType $root, string $entries, int $depth, array &$seen): array
    {
        if ($root === null) {
            return [];
        }
        $this->values->budget->depth($depth);
        $this->values->budget->consume('nodes');
        $dictionary = $this->values->dictionary($root);
        if ($dictionary === null) {
            throw new ReadingException('A name or number tree node must be a dictionary.');
        }
        $identity = spl_object_id($dictionary);
        if (isset($seen[$identity])) {
            throw new ReadingException('A name or number tree contains a cycle or repeated node.');
        }
        $seen[$identity] = true;
        $result = [];
        $pairs = $this->values->value($dictionary, $entries);
        $kids = $this->values->value($dictionary, 'Kids');
        if ($pairs !== null && $kids !== null) {
            throw new ReadingException('A name or number tree node cannot contain both entries and Kids.');
        }
        if ($pairs !== null) {
            if (!$pairs instanceof ArrayType || count($pairs) % 2 !== 0) {
                throw new ReadingException('A name or number tree leaf requires key/value pairs.');
            }
            for ($index = 0; $index < count($pairs); $index += 2) {
                $this->values->budget->consume('nodes');
                $result[] = [$pairs->items[$index], $pairs->items[$index + 1]];
            }
        }
        if ($kids !== null) {
            if (!$kids instanceof ArrayType) {
                throw new ReadingException('A name or number tree Kids value must be an array.');
            }
            foreach ($kids as $kid) {
                array_push($result, ...$this->treeNode($kid, $entries, $depth + 1, $seen));
            }
        }
        return $result;
    }
    /** @return array<int, string> */
    public function pageLabels(DictionaryType $catalog, int $pages): array
    {
        $ranges = [];
        foreach ($this->tree($catalog->get('PageLabels'), 'Nums') as [$key, $value]) {
            $key = $this->values->document->resolve($key);
            $dictionary = $this->values->dictionary($value);
            if (!$key instanceof IntegerType || $key->integer() < 0 || $key->integer() >= $pages || $dictionary === null) {
                throw new ReadingException('A page label range has an invalid start or dictionary.');
            }
            if (isset($ranges[$key->integer()])) {
                throw new ReadingException('A page label range start must be unique.');
            }
            $ranges[$key->integer()] = $dictionary;
        }
        ksort($ranges);
        $result = [];
        $current = null;
        $start = 0;
        for ($page = 0; $page < $pages; ++$page) {
            if (isset($ranges[$page])) {
                $current = $ranges[$page];
                $start = $page;
            }
            if ($current === null) {
                $result[$page + 1] = (string) ($page + 1);
                continue;
            }
            $number = $this->values->integer($current->get('St'), 1) + $page - $start;
            if ($number < 1) {
                throw new ReadingException('A page label starting number must be positive.');
            }
            $style = $this->values->name($current->get('S'));
            $suffix = match ($style) {
                'D' => (string) $number, 'R' => $this->roman($number), 'r' => strtolower($this->roman($number)), 'A' => $this->letters($number), 'a' => strtolower($this->letters($number)), null => '', default => throw new ReadingException('A page label style is unsupported.')
            };
            $result[$page + 1] = ($this->values->text($current->get('P')) ?? '') . $suffix;
        }
        return $result;
    }
    private function roman(int $number): string
    {
        if ($number > 100000) {
            throw new ReadingException('A Roman page label exceeds the adaptation limit.');
        }
        $result = '';
        foreach ([1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'] as $value => $letters) {
            while ($number >= $value) {
                $result .= $letters;
                $number -= $value;
            }
        }
        return $result;
    }
    private function letters(int $number): string
    {
        $repeat = intdiv($number - 1, 26) + 1;
        if ($repeat > 10000) {
            throw new ReadingException('An alphabetic page label exceeds the adaptation limit.');
        }
        return str_repeat(chr(65 + ($number - 1) % 26), $repeat);
    }
    public function script(?BasicType $action): ?string
    {
        $dictionary = $this->values->dictionary($action);
        if ($dictionary === null || $this->values->name($dictionary->get('S')) !== 'JavaScript') {
            return null;
        }
        $script = $this->values->value($dictionary, 'JS');
        if ($script instanceof EncodedStream) {
            $script = new LiteralString($this->values->document->streamData($script));
        }
        return $this->values->text($script);
    }
    /** @return array<string, string> */
    public function additionalScripts(DictionaryType $owner): array
    {
        $dictionary = $this->values->dictionary($owner->get('AA'));
        $scripts = [];
        foreach ($dictionary?->entries() ?? [] as $entry) {
            $script = $this->script($entry->value);
            if ($script !== null) {
                $scripts[$entry->key->value] = $script;
            }
        }
        return $scripts;
    }
}
