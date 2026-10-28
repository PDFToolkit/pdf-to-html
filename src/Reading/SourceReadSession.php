<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Html\Forms\ChoiceOption;
use PDF\Html\Forms\FieldType;
use PDF\Html\Forms\ResetFormAction;
use PDF\Html\Forms\SubmitFormAction;
use PDF\Html\AssociatedFileSelection;
use PDF\Html\RoleResolver;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\FormField;
use PDF\Html\Model\GraphicContent;
use PDF\Html\Model\LinkAnnotation;
use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\AssociatedFile;
use PDF\Html\Model\StructureProperties;
use PDF\Html\Model\RoleMapping;
use PDF\Html\Model\SourceNode;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\AuthenticatedPermissions;
use PDF\Html\Model\Widget;
use PDF\Reading\EncodedStream;
use PDF\Reading\PageTreeLimits;
use PDF\Reading\PageTreeReader;
use PDF\Reading\ReadPage;
use PDF\Document\Navigation\DestinationView;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** State and caches belong to exactly one adaptation; original dictionaries are retained. */
final class SourceReadSession
{
    private ContentReader $content;
    private DocumentMetadataReader $metadata;
    private ParentTreeValidator $inverse;
    /** @var array<string, ReadPage> */
    private array $pages = [];
    /** @var array<int, int> */
    private array $pageNumbers = [];
    /** @var array<int, true> */
    private array $seenElements = [];
    /** @var array<int, true> */
    private array $knownStructures = [];
    /** @var array<int, true> */
    private array $seenNamespaces = [];
    /** @var list<RoleMapping> */
    private array $roles = [];
    /** @var array<string, BasicType> */
    private array $stringDestinations = [];
    /** @var array<string, BasicType> */
    private array $nameDestinations = [];
    /** @var array<int, ReadPage> */
    private array $annotationPages = [];
    /** @var array<int, list<AssociatedFile>> */
    private array $associated = [];
    public function __construct(private readonly SourceValues $values, private readonly AssociatedFileSelection $selection = new AssociatedFileSelection(), StructureValidationMode $structureValidation = StructureValidationMode::Permissive, ?InlineImageBoundaryProvider $inlineImages = null)
    {
        $this->content = new ContentReader($values, $inlineImages);
        $this->metadata = new DocumentMetadataReader($values);
        $this->inverse = new ParentTreeValidator($values, $structureValidation);
    }
    public function read(string $filename): TaggedDocument
    {
        $catalog = $this->values->document->catalog();
        $tree = (new PageTreeReader($this->values->document, new PageTreeLimits(maxDepth: $this->values->budget->limits->maximumDepth, maxNodes: $this->values->budget->limits->maximumNodes, maxPages: $this->values->budget->limits->maximumPages)))->read();
        foreach ($tree as $index => $page) {
            $this->values->budget->consume('nodes');
            $this->pages[$this->values->key($page->reference)] = $page;
            $this->pageNumbers[spl_object_id($page->dictionary)] = $index + 1;
            $this->content->registerPage($page, $index + 1);
            foreach ($this->values->items($page->dictionary->get('Annots')) as $annotation) {
                $this->values->budget->consume('nodes');
                $dictionary = $this->values->dictionary($annotation);
                if ($dictionary !== null) {
                    if (isset($this->annotationPages[spl_object_id($dictionary)])) {
                        throw new ReadingException('An annotation occurs more than once in the page Annots arrays.');
                    }
                    $this->annotationPages[spl_object_id($dictionary)] = $page;
                }
            }
        }
        $root = $this->values->dictionary($catalog->get('StructTreeRoot'));
        if ($root === null) {
            throw new ReadingException('HTML derivation requires a tagged PDF structure tree.');
        }
        $roles = $this->values->dictionary($root->get('RoleMap'));
        foreach ($roles?->entries() ?? [] as $entry) {
            $this->values->budget->consume('nodes');
            $target = $this->values->name($entry->value);
            if ($target === null) {
                throw new ReadingException('A default RoleMap target must be a name.');
            }
            $this->roles[] = new RoleMapping($entry->key->value, null, $target);
        }
        foreach ($this->values->items($root->get('Namespaces')) as $namespace) {
            $this->namespace($namespace, 1);
        }
        $classes = [];
        $classMap = $this->values->dictionary($root->get('ClassMap'));
        foreach ($classMap?->entries() ?? [] as $entry) {
            $classes[$entry->key->value] = $this->attributes($entry->value);
        }
        // Discover every replacement before any shared page is interpreted.
        // Association decisions use the same explicit policy as HTML derivation.
        $replacementElements = [];
        $this->replacements($root->get('K'), null, false, 1, $replacementElements);
        $this->knownStructures = $replacementElements;
        $names = $this->values->dictionary($catalog->get('Names'));
        foreach ($this->metadata->tree($names?->get('Dests'), 'Names') as [$name, $target]) {
            $name = $this->values->document->resolve($name);
            if (!$name instanceof StringType) {
                throw new ReadingException('A destination name-tree key must be a byte string.');
            }
            $this->values->budget->consume('retainedText', strlen($name->value) * 2);
            $key = bin2hex($name->value);
            if (isset($this->stringDestinations[$key])) {
                throw new ReadingException('Destination name-tree byte-string keys must be unique.');
            }
            $this->stringDestinations[$key] = $target;
        }
        $legacyDests = $this->values->dictionary($catalog->get('Dests'));
        foreach ($legacyDests?->entries() ?? [] as $entry) {
            $this->values->budget->consume('retainedText', strlen($entry->key->value) * 2);
            $this->nameDestinations[bin2hex($entry->key->value)] = $entry->value;
        }
        $structure = [];
        foreach ($this->values->items($root->get('K')) as $kid) {
            $dictionary = $this->values->dictionary($kid);
            if ($dictionary === null) {
                throw new ReadingException('Structure-tree roots require structure-element children.');
            }
            $structure[] = $this->element($kid, $dictionary, $root, null, 1);
        }
        if ($structure === []) {
            throw new ReadingException('The tagged PDF has no derivable structure elements.');
        }
        $this->inverse->validate($root);
        $scripts = [];
        foreach ($this->metadata->tree($names?->get('JavaScript'), 'Names') as [, $action]) {
            $script = $this->metadata->script($action);
            if ($script !== null) {
                $scripts[] = $script;
            }
        }
        $open = $this->metadata->script($catalog->get('OpenAction'));
        if ($open !== null) {
            $scripts[] = $open;
        }
        if ($catalog->get('AA') !== null) {
            $this->values->budget->diagnostic('Document additional-action timing is not represented by the HTML event model; catalog /AA actions were not scheduled.');
        }
        $pageScripts = [];
        foreach ($tree as $index => $page) {
            $pageScripts[$index + 1] = $this->metadata->additionalScripts($page->dictionary);
        }
        $permissions = $this->values->document->permissions();
        return new TaggedDocument($filename, $structure, $this->values->text($catalog->get('Lang')), $this->metadata->title($catalog), $classes, $this->roles, $this->metadata->associatedFiles($catalog, $this->selection, catalog: true), $catalog->get('AcroForm') !== null, $permissions === null ? null : new AuthenticatedPermissions($permissions->effectiveMask(), true), $this->metadata->pageLabels($catalog, count($tree)), $scripts, $pageScripts, $this->values->budget->diagnostics);
    }

    /**
     * ActualText replaces every descendant content item (4.3.6.3, physical pages 40–41).
     * This pass preserves graph validation and never interprets replacement glyphs/assets.
     * @param array<int, true> $seen
     */
    private function replacements(?BasicType $children, ?ReadPage $page, bool $replaced, int $depth, array &$seen, bool $replaceDirect = false): void
    {
        $this->values->budget->depth($depth);
        foreach ($this->values->items($children) as $child) {
            $this->values->budget->consume('nodes');
            $child = $this->values->document->resolve($child);
            if ($child instanceof DictionaryType && $child->get('S') !== null) {
                $identity = spl_object_id($child);
                if (isset($seen[$identity])) {
                    throw new ReadingException('The structure graph contains a repeated element or cycle.');
                }
                $seen[$identity] = true;
                $childPage = $child->get('Pg') === null ? $page : $this->page($child->get('Pg'));
                [$replacement, $childDirect] = $this->disposition($child, $replaced);
                $this->replacements($child->get('K'), $childPage, $replacement, $depth + 1, $seen, $childDirect);
            } elseif ($replaceDirect && $child instanceof IntegerType && $child->integer() >= 0 && $page !== null) {
                $this->content->replaceMarked($page, $child->integer());
            } elseif ($replaceDirect && $child instanceof DictionaryType && $this->values->name($child->get('Type')) === 'MCR') {
                $contentPage = $child->get('Pg') === null ? $page : $this->page($child->get('Pg'));
                $id = $this->values->value($child, 'MCID');
                $stream = $this->values->value($child, 'Stm');
                if ($contentPage !== null && $id instanceof IntegerType && $id->integer() >= 0 && ($stream === null || $stream instanceof EncodedStream)) {
                    $this->content->replaceMarked($contentPage, $id->integer(), $stream);
                }
            }
        }
    }
    private function element(BasicType $source, DictionaryType $dictionary, DictionaryType $parent, ?ReadPage $page, int $depth, bool $replaced = false): StructureElement
    {
        $this->values->budget->depth($depth);
        $this->values->budget->consume('nodes');
        $identity = spl_object_id($dictionary);
        if (isset($this->seenElements[$identity])) {
            throw new ReadingException('The structure graph contains a repeated element or cycle.');
        }
        $this->seenElements[$identity] = true;
        $this->inverse->element($dictionary);
        if ($this->values->dictionary($dictionary->get('P')) !== $parent) {
            throw new ReadingException('A structure element has an inconsistent parent reference.');
        }
        $type = $this->values->name($dictionary->get('S'));
        if ($type === null) {
            throw new ReadingException('A structure element requires its S type name.');
        }
        $page = $dictionary->get('Pg') !== null ? $this->page($dictionary->get('Pg')) : $page;
        [$replaced, $replaceDirect] = $this->disposition($dictionary, $replaced);
        [$namespace, $namespaceIdentity] = $this->namespace($dictionary->get('NS'), $depth + 1);
        $children = [];
        foreach ($this->values->items($dictionary->get('K')) as $kid) {
            $resolved = $this->values->document->resolve($kid);
            if ($resolved instanceof NullType) {
                continue;
            }
            if ($resolved instanceof IntegerType) {
                if ($page === null || $resolved->integer() < 0) {
                    throw new ReadingException('A bare structure MCID requires an associated source page.');
                }
                $this->inverse->associate($page->dictionary, $dictionary, $resolved->integer());
                $children[] = $replaceDirect ? new MarkedContent([], new StructureProperties(), $this->number($page)) : $this->content->marked($page, $resolved->integer());
            } elseif ($resolved instanceof DictionaryType && $resolved->get('S') !== null) {
                $children[] = $this->element($kid, $resolved, $dictionary, $page, $depth + 1, $replaced);
            } elseif ($resolved instanceof DictionaryType && $this->values->name($resolved->get('Type')) === 'MCR') {
                $contentPage = $resolved->get('Pg') === null ? $page : $this->page($resolved->get('Pg'));
                $id = $this->values->value($resolved, 'MCID');
                $stream = $this->values->value($resolved, 'Stm');
                if ($contentPage === null || !$id instanceof IntegerType || $id->integer() < 0 || ($stream !== null && !$stream instanceof EncodedStream)) {
                    throw new ReadingException('An MCR requires its source page, nonnegative MCID and optional stream.');
                }
                $this->inverse->associate($stream instanceof EncodedStream ? $stream->dictionary() : $contentPage->dictionary, $dictionary, $id->integer());
                $children[] = $replaceDirect ? new MarkedContent([], new StructureProperties(), $this->number($contentPage)) : $this->content->marked($contentPage, $id->integer(), $stream instanceof EncodedStream ? $stream : null);
            } elseif ($resolved instanceof DictionaryType && $this->values->name($resolved->get('Type')) === 'OBJR') {
                $objectPage = $resolved->get('Pg') === null ? $page : $this->page($resolved->get('Pg'));
                $object = $resolved->get('Obj');
                if ($object === null) {
                    throw new ReadingException('An OBJR requires its Obj reference.');
                }
                $resolvedObject = $this->values->document->resolve($object);
                $container = $resolvedObject instanceof EncodedStream ? $resolvedObject->dictionary() : $resolvedObject;
                if ($container instanceof DictionaryType) {
                    $this->inverse->associate($container, $dictionary);
                }
                $children[] = $this->object($object, $objectPage, $depth + 1, $replaceDirect);
            } else {
                throw new ReadingException('A structure child is neither an element, marked-content reference nor object reference.');
            }
        }
        $classNames = [];
        foreach ($this->values->items($dictionary->get('C')) as $class) {
            $name = $this->values->name($class);
            if ($name !== null) {
                $classNames[] = $name;
            }
        }
        return new StructureElement($this->values->key($source), $type, $children, $namespace, $this->values->properties($dictionary, $classNames), $this->attributes($dictionary->get('A')), $this->associated($dictionary), $namespaceIdentity);
    }

    /** @return array{bool, bool} Replacement of every descendant, then direct content only. */
    private function disposition(DictionaryType $dictionary, bool $inherited): array
    {
        $identity = spl_object_id($dictionary);
        if ($inherited || $this->values->value($dictionary, 'ActualText') instanceof StringType) {
            $this->associated[$identity] = [];
            return [true, true];
        }
        $type = $this->resolvedType($dictionary);
        if (in_array($type, ['Private', 'Artifact', 'Annot'], true)) {
            $this->associated[$identity] = [];
            return [true, true];
        }
        if ($type === 'Formula' && $this->hasMathChild($dictionary)) {
            $this->associated[$identity] = [];
            return [false, false];
        }
        $all = $direct = false;
        foreach ($this->associated($dictionary) as $file) {
            $all = $all || $this->selection->replaces($file);
            $direct = $direct || $this->selection->supplements($file);
        }
        return [$all, $all || $direct];
    }

    private function resolvedType(DictionaryType $dictionary): ?string
    {
        $type = $this->values->name($dictionary->get('S'));
        if ($type === null) {
            return null;
        }
        [$namespace, $identity] = $this->namespace($dictionary->get('NS'), 1);
        return (new RoleResolver($this->roles, $this->selection->limits, $this->selection->policy))->resolve(new StructureElement('association-type', $type, namespace: $namespace, namespaceIdentity: $identity))->type;
    }

    private function hasMathChild(DictionaryType $dictionary): bool
    {
        foreach ($this->values->items($dictionary->get('K')) as $child) {
            $child = $this->values->dictionary($child);
            if ($child === null || $child->get('S') === null) {
                continue;
            }
            [$namespace, $identity] = $this->namespace($child->get('NS'), 1);
            $type = $this->values->name($child->get('S'));
            if ($type !== null) {
                $role = (new RoleResolver($this->roles, $this->selection->limits, $this->selection->policy))->resolve(new StructureElement('association-math', $type, namespace: $namespace, namespaceIdentity: $identity));
                if ($role->type === 'math' && $role->namespace === 'http://www.w3.org/1998/Math/MathML') {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return list<AssociatedFile> */
    private function associated(DictionaryType $owner): array
    {
        $identity = spl_object_id($owner);
        return $this->associated[$identity] ??= $this->metadata->associatedFiles($owner, $this->selection);
    }
    /** @return array{?string, ?string} */
    private function namespace(?BasicType $source, int $depth): array
    {
        if ($source === null) {
            return [null, null];
        }
        $this->values->budget->depth($depth);
        $dictionary = $this->values->dictionary($source);
        if ($dictionary === null) {
            throw new ReadingException('A structure namespace must be a dictionary.');
        }
        $name = $this->values->text($dictionary->get('NS'));
        if ($name === null || $name === '') {
            throw new ReadingException('A namespace requires its nonempty NS identifier.');
        }
        $identity = $this->values->key($source);
        $objectId = spl_object_id($dictionary);
        if (!isset($this->seenNamespaces[$objectId])) {
            $this->seenNamespaces[$objectId] = true;
            $map = $this->values->dictionary($dictionary->get('RoleMapNS'));
            foreach ($map?->entries() ?? [] as $entry) {
                $this->values->budget->consume('nodes');
                $target = $this->values->document->resolve($entry->value);
                if ($target instanceof NameType) {
                    $this->roles[] = new RoleMapping($entry->key->value, $name, $target->value, $name, $identity, $identity);
                } elseif ($target instanceof ArrayType && count($target) === 2) {
                    $targetName = $this->values->name($target->items[0]);
                    [$targetNamespace, $targetIdentity] = $this->namespace($target->items[1], $depth + 1);
                    if ($targetName === null) {
                        throw new ReadingException('A namespace role mapping target requires a type name.');
                    }
                    $this->roles[] = new RoleMapping($entry->key->value, $name, $targetName, $targetNamespace, $identity, $targetIdentity);
                } else {
                    throw new ReadingException('A namespace role mapping requires a name or type/namespace pair.');
                }
            }
        }
        return [$name, $identity];
    }
    /** @return list<AttributeObject> */
    private function attributes(?BasicType $source): array
    {
        $result = [];
        foreach ($this->values->items($source) as $value) {
            $value = $this->values->document->resolve($value);
            if ($value instanceof IntegerType) {
                continue;
            }
            if (!$value instanceof DictionaryType) {
                throw new ReadingException('Structure attributes must be dictionaries with optional revision numbers.');
            }
            $owner = $this->values->name($value->get('O'));
            if ($owner === null) {
                throw new ReadingException('A structure attribute requires its O owner name.');
            }
            [$namespace, $identity] = $this->namespace($value->get('NS'), 1);
            $entries = [];
            foreach ($value->entries() as $entry) {
                if (!in_array($entry->key->value, ['O', 'NS'], true)) {
                    $entries[$entry->key->value] = $owner === 'Table' && $entry->key->value === 'Headers' ? $this->headers($entry->value) : $this->values->native($entry->value);
                }
            }
            $result[] = new AttributeObject($owner, $entries, $namespace, $identity);
        }
        return $result;
    }

    /** @return list<string> */
    private function headers(BasicType $source): array
    {
        $value = $this->values->document->resolve($source);
        if (!$value instanceof ArrayType) {
            throw new ReadingException('Table Headers must be an array of structure-identifier byte strings.');
        }
        $headers = [];
        foreach ($value as $header) {
            $this->values->budget->consume('nodes');
            $identifier = $this->values->identifier($header);
            if ($identifier === null) {
                throw new ReadingException('Table Headers entries must be structure-identifier byte strings.');
            }
            $headers[] = $identifier;
        }
        return $headers;
    }
    private function page(?BasicType $source): ReadPage
    {
        if ($source === null) {
            throw new ReadingException('A content reference has no source page.');
        }
        return $this->pages[$this->values->key($source)] ?? throw new ReadingException('A content reference points outside the validated source page tree.');
    }
    private function object(BasicType $source, ?ReadPage $page, int $depth, bool $replaced = false): SourceNode
    {
        $this->values->budget->depth($depth);
        $object = $this->values->document->resolve($source);
        if ($replaced) {
            if (!$object instanceof EncodedStream && !$object instanceof DictionaryType) {
                throw new ReadingException('An object reference does not resolve to an annotation or XObject.');
            }
            $page ??= $object instanceof DictionaryType ? $this->annotationPages[spl_object_id($object)] ?? null : null;
            return new ContentGroup([], $page === null ? null : $this->number($page));
        }
        if ($object instanceof EncodedStream) {
            if ($page === null) {
                throw new ReadingException('An object-reference XObject requires a source page.');
            }
            $subtype = $this->values->name($object->dictionary()->get('Subtype'));
            if ($subtype === 'Image') {
                $this->content->page($page);
                return $this->content->image($object, $this->number($page));
            }
            if ($subtype === 'Form') {
                $this->content->page($page);
                return new ContentGroup($this->content->stream($page, $object, $page->attributes->resources, $depth + 1), $this->number($page));
            }
            return new GraphicContent('unsupported-object', $subtype ?? 'unknown XObject', page: $this->number($page));
        }
        if (!$object instanceof DictionaryType) {
            throw new ReadingException('An object reference does not resolve to an annotation or XObject.');
        }
        $page ??= $this->annotationPages[spl_object_id($object)] ?? null;
        $subtype = $this->values->name($object->get('Subtype'));
        if ($subtype === 'Link') {
            $seenActions = [];
            $action = $this->navigationAction($object->get('A'), 1, $seenActions);
            if ($action !== null && $this->values->name($action->get('S')) === 'URI') {
                return new LinkAnnotation(uri: $this->values->text($action->get('URI')));
            }
            if ($object->get('A') !== null && $action === null) {
                $this->values->budget->diagnostic('The link action sequence has no URI or GoTo action; other action types require an explicit application navigation adapter.');
            }
            $destination = $action?->get('SD') ?? $action?->get('D') ?? $object->get('Dest');
            return $this->destination($destination);
        }
        if ($subtype === 'Widget') {
            $membership = $this->annotationPages[spl_object_id($object)] ?? null;
            if ($membership !== null && $page !== null && $membership !== $page) {
                throw new ReadingException('A widget OBJR page does not agree with its page Annots membership.');
            }
            if ($membership !== null && $object->get('P') !== null && $this->page($object->get('P')) !== $membership) {
                throw new ReadingException('A widget P page does not agree with its page Annots membership.');
            }
            return $this->widget($object, $membership);
        }
        return new GraphicContent('unsupported-annotation', $subtype ?? 'missing subtype', page: $page === null ? null : $this->number($page));
    }

    /** @param array<int, true> $seen */
    private function navigationAction(?BasicType $source, int $depth, array &$seen): ?DictionaryType
    {
        if ($source === null) {
            return null;
        }
        $this->values->budget->depth($depth);
        $this->values->budget->consume('nodes');
        $action = $this->values->document->resolve($source);
        if (!$action instanceof DictionaryType && !$action instanceof ArrayType) {
            throw new ReadingException('A link action sequence must contain action dictionaries.');
        }
        $identity = spl_object_id($action);
        if (isset($seen[$identity])) {
            throw new ReadingException('A link action sequence contains a cycle.');
        }
        $seen[$identity] = true;
        try {
            if ($action instanceof ArrayType) {
                foreach ($action as $child) {
                    $found = $this->navigationAction($child, $depth + 1, $seen);
                    if ($found !== null) {
                        return $found;
                    }
                }
                return null;
            }
            if (in_array($this->values->name($action->get('S')), ['URI', 'GoTo'], true)) {
                return $action;
            }
            return $this->navigationAction($action->get('Next'), $depth + 1, $seen);
        } finally {
            unset($seen[$identity]);
        }
    }
    private function destination(?BasicType $destination): LinkAnnotation
    {
        $seen = [];
        for ($depth = 1; ; ++$depth) {
            $this->values->budget->depth($depth);
            $this->values->budget->consume('nodes');
            if ($destination === null) {
                return new LinkAnnotation();
            }
            $destination = $this->values->document->resolve($destination);
            if ($destination instanceof NameType || $destination instanceof StringType) {
                $this->values->budget->consume('retainedText', strlen($destination->value) * 2);
                $name = bin2hex($destination->value);
                $identity = ($destination instanceof NameType ? 'name:' : 'string:') . $name;
                if (isset($seen[$identity])) {
                    throw new ReadingException('A named destination is invalid or cyclic.');
                }
                $seen[$identity] = true;
                $destination = ($destination instanceof NameType ? ($this->nameDestinations[$name] ?? null) : ($this->stringDestinations[$name] ?? null)) ?? throw new ReadingException('A named destination is missing.');
                continue;
            }
            if ($destination instanceof DictionaryType) {
                $identity = 'dictionary:' . spl_object_id($destination);
                if (isset($seen[$identity])) {
                    throw new ReadingException('Destination dictionaries contain a cycle.');
                }
                $seen[$identity] = true;
                $destination = $destination->get('SD') ?? $destination->get('D');
                continue;
            }
            break;
        }
        if (!$destination instanceof ArrayType || count($destination) < 2) {
            throw new ReadingException('An explicit destination requires a target and fit name.');
        }
        $fit = $this->values->document->resolve($destination->items[1]);
        if (!$fit instanceof NameType) {
            throw new ReadingException('A destination fit type must be a name.');
        }
        try {
            new DestinationView($fit, ...array_map($this->values->document->resolve(...), array_slice($destination->items, 2)));
        } catch (\InvalidArgumentException $exception) {
            throw new ReadingException('Invalid destination view: ' . $exception->getMessage(), previous: $exception);
        }
        $target = $destination->items[0];
        $key = $this->values->key($target);
        if (isset($this->pages[$key])) {
            return new LinkAnnotation(pageTarget: $this->number($this->pages[$key]));
        }
        $dictionary = $this->values->dictionary($target);
        if ($dictionary !== null && $dictionary->get('S') !== null) {
            if (!isset($this->knownStructures[spl_object_id($dictionary)])) {
                throw new ReadingException('A structure destination must belong to this source structure tree.');
            }
            return new LinkAnnotation(structureTarget: $key);
        }
        $this->values->budget->diagnostic('An explicit link destination does not identify a known source page or structure element.');
        return new LinkAnnotation();
    }
    private function number(ReadPage $page): int
    {
        return $this->pageNumbers[spl_object_id($page->dictionary)];
    }

    private function widget(DictionaryType $annotation, ?ReadPage $page): Widget
    {
        $chain = [];
        $seen = [];
        $current = $annotation;
        do {
            $identity = spl_object_id($current);
            if (isset($seen[$identity])) {
                throw new ReadingException('Form-field inheritance contains a cycle.');
            }
            $seen[$identity] = true;
            $this->values->budget->depth(count($chain) + 1);
            $chain[] = $current;
            $current = $this->values->dictionary($current->get('Parent'));
        } while ($current !== null);
        $inherit = function (string $key) use ($chain): ?BasicType {
            foreach ($chain as $field) {
                if ($field->get($key) !== null) {
                    return $this->values->value($field, $key);
                }
            } return null;
        };
        $name = $this->fieldName($annotation);
        if ($name === '') {
            throw new ReadingException('A widget requires a fully qualified source field name.');
        }
        $type = FieldType::tryFrom($this->values->name($inherit('FT')) ?? '');
        if ($type === null) {
            throw new ReadingException('A widget field has an unsupported or missing field type.');
        }
        $options = [];
        foreach ($this->values->items($inherit('Opt')) as $option) {
            $option = $this->values->document->resolve($option);
            if ($option instanceof ArrayType && count($option) === 2) {
                $export = $this->values->text($option->items[0]);
                $display = $this->values->text($option->items[1]);
            } else {
                $export = $display = $this->values->text($option);
            }
            if ($export === null || $display === null) {
                throw new ReadingException('A field choice option requires text values.');
            }
            $options[] = new ChoiceOption($export, $display);
        }
        $fieldValue = $this->fieldValue($inherit('V'));
        $defaultValue = $this->fieldValue($inherit('DV'));
        $indices = [];
        foreach ($this->values->items($inherit('I')) as $index) {
            if (!$index instanceof IntegerType) {
                throw new ReadingException('Selected field indices must be integers.');
            } $indices[] = $index->integer();
        }
        $actions = [];
        foreach (array_reverse($chain) as $field) {
            $actions = array_replace($actions, $this->metadata->additionalScripts($field));
        }
        $actions = array_intersect_key($actions, array_fill_keys(['K', 'V', 'C', 'F'], true));
        $widgetActions = array_intersect_key($this->metadata->additionalScripts($annotation), array_fill_keys(['Fo', 'Bl', 'E', 'X', 'D', 'U'], true));
        $action = $this->values->dictionary($annotation->get('A'));
        $actionType = $action === null ? null : $this->values->name($action->get('S'));
        if ($actionType === 'JavaScript') {
            $script = $this->metadata->script($action);
            if ($script !== null) {
                $widgetActions['A'] = $script;
            }
        }
        $target = $action === null ? null : $this->values->text($action->get('F'));
        if ($action !== null && $target === null) {
            $file = $this->values->dictionary($action->get('F'));
            $target = $file === null ? null : $this->values->text($file->get('F'));
        }
        $actionFlags = $action === null ? 0 : $this->actionFlags($action);
        $reset = $submit = null;
        if ($actionType === 'ResetForm' && $action !== null) {
            if (($actionFlags & ~1) !== 0) {
                $this->values->budget->diagnostic('ResetForm flags outside the defined Include/Exclude bit were ignored.');
            }
            $reset = new ResetFormAction($this->actionFields($action), ($actionFlags & 1) !== 0);
        } elseif ($actionType === 'SubmitForm' && $action !== null && $target !== null) {
            $submit = new SubmitFormAction($target, $actionFlags, $this->actionFields($action));
        }
        $field = new FormField($name, $type, $this->values->integer($inherit('Ff')), $fieldValue, $options, $indices, $inherit('MaxLen') === null ? null : $this->values->integer($inherit('MaxLen')), $this->values->text($inherit('RV')), $actions, $actionType, $target, $actionFlags, defaultValue: $defaultValue, resetAction: $reset, submitAction: $submit, mappingName: $this->values->text($inherit('TM')));
        $appearance = $this->values->dictionary($annotation->get('AP'));
        $normal = $appearance === null ? null : $this->values->value($appearance, 'N');
        $states = [];
        if ($normal instanceof DictionaryType) {
            foreach ($normal->entries() as $entry) {
                if ($entry->key->value !== 'Off') {
                    $states[] = $entry->key->value;
                }
            }
        }
        $caption = $this->values->dictionary($annotation->get('MK'));
        $border = $this->values->dictionary($annotation->get('BS'));
        $highlighting = $this->values->value($annotation, 'H');
        if ($highlighting !== null && !$highlighting instanceof NullType && !$highlighting instanceof NameType) {
            throw new ReadingException('A widget highlighting mode must be a PDF name.');
        }
        $rect = $this->rectangle($annotation->get('Rect'));
        $pageBox = $page === null ? null : $this->rectangle($page->attributes->cropBox);
        $optionIndex = null;
        if (count($chain) > 1) {
            foreach ($this->values->items($chain[1]->get('Kids')) as $index => $kid) {
                if ($this->values->dictionary($kid) === $annotation) {
                    $optionIndex = $index;
                    break;
                }
            }
        }
        $appearanceState = $this->values->name($annotation->get('AS'));
        $appearanceNodes = $this->appearanceNodes($page, $normal, $appearanceState, $rect, 'normal:' . spl_object_id($annotation));
        $down = $appearance === null ? null : $this->values->value($appearance, 'D');
        $rollover = $appearance === null ? null : $this->values->value($appearance, 'R');
        return new Widget($field, $page === null ? null : $this->number($page), $this->values->integer($annotation->get('F')), $rect, $pageBox, $appearanceState ?? 'Off', $states, $caption === null ? null : $this->values->text($caption->get('CA')), $appearanceNodes, $this->values->name($annotation->get('H')), $border === null ? null : $this->values->name($border->get('S')), $border === null ? null : $this->values->number($border->get('W'), 1), $caption === null ? null : $this->numericArray($caption->get('BC')), $caption === null ? null : $this->numericArray($caption->get('BG')), $inherit('Q') === null ? null : $this->values->integer($inherit('Q')), $optionIndex, $type === FieldType::Signature ? 'Signature validation is not performed by HTML derivation.' : null, actions: $widgetActions, downAppearance: $this->appearanceNodes($page, $down, $appearanceState, $rect, 'down:' . spl_object_id($annotation)), rolloverAppearance: $this->appearanceNodes($page, $rollover, $appearanceState, $rect, 'rollover:' . spl_object_id($annotation)));
    }

    /**
     * @param array{int|float, int|float, int|float, int|float} $rectangle
     * @return list<SourceNode>
     */
    private function appearanceNodes(?ReadPage $page, ?BasicType $appearance, ?string $state, array $rectangle, string $invocation): array
    {
        if ($page === null || $appearance === null || $appearance instanceof NullType) {
            return [];
        }
        if ($appearance instanceof DictionaryType) {
            // An unmatched or absent AS paints no state; do not substitute Off or the first entry.
            $appearance = $state === null ? null : $this->values->value($appearance, $state);
            if ($appearance === null || $appearance instanceof NullType) {
                return [];
            }
        }
        if (!$appearance instanceof EncodedStream) {
            throw new ReadingException('A selected widget appearance must resolve to a stream.');
        }
        if ($rectangle[0] == $rectangle[2] || $rectangle[1] == $rectangle[3]) {
            return [];
        }
        $box = $this->rectangle($appearance->dictionary()->get('BBox'));
        if ($box[0] == $box[2] || $box[1] == $box[3]) {
            $this->values->budget->diagnostic('A widget appearance has an empty bounding box and paints no content.');
            return [];
        }
        $matrix = $this->values->value($appearance->dictionary(), 'Matrix');
        if ($matrix !== null && !$matrix instanceof NullType && !$matrix instanceof ArrayType) {
            throw new ReadingException('An appearance Matrix requires six numeric coefficients.');
        }
        $placement = new AppearancePlacement($box, $matrix instanceof ArrayType ? ContentMatrix::operands($matrix->items) : new ContentMatrix(), $rectangle);
        $initial = (new PageTransform($page))->matrix->concatenate($placement->beforeFormMatrix);
        return $this->content->stream($page, $appearance, $page->attributes->resources, 1, initialMatrix: $initial, invocation: 'appearance:' . $invocation);
    }

    private function fieldName(DictionaryType $field): string
    {
        $names = [];
        $seen = [];
        do {
            $this->values->budget->depth(count($seen) + 1);
            $identity = spl_object_id($field);
            if (isset($seen[$identity])) {
                throw new ReadingException('Form-field name inheritance contains a cycle.');
            }
            $seen[$identity] = true;
            $partial = $this->values->text($field->get('T'));
            if ($partial !== null && $partial !== '') {
                array_unshift($names, $partial);
            }
            $parent = $field->get('Parent');
            $field = $this->values->dictionary($parent);
            if ($parent !== null && $field === null) {
                throw new ReadingException('A form-field Parent must resolve to a field dictionary.');
            }
        } while ($field !== null);
        return implode('.', $names);
    }

    /** @return string|list<string>|null */
    private function fieldValue(?BasicType $value): string|array|null
    {
        if ($value instanceof ArrayType) {
            $result = [];
            foreach ($value as $item) {
                $this->values->budget->consume('nodes');
                $text = $this->values->text($item) ?? $this->values->name($item);
                if ($text === null) {
                    throw new ReadingException('A form-field value array must contain strings or names.');
                }
                $result[] = $text;
            }
            return $result;
        }
        return $this->values->text($value) ?? $this->values->name($value);
    }

    private function actionFlags(DictionaryType $action): int
    {
        $flags = $this->values->value($action, 'Flags');
        if ($flags !== null && (!$flags instanceof IntegerType || $flags->integer() < 0)) {
            throw new ReadingException('Form action Flags must be a nonnegative integer.');
        }
        return $flags instanceof IntegerType ? $flags->integer() : 0;
    }

    /** @return list<string>|null */
    private function actionFields(DictionaryType $action): ?array
    {
        $fields = $this->values->value($action, 'Fields');
        if ($fields === null) {
            return null;
        }
        if (!$fields instanceof ArrayType) {
            throw new ReadingException('A form action Fields selection must be an array.');
        }
        $names = [];
        foreach ($fields as $field) {
            $this->values->budget->consume('nodes');
            $field = $this->values->document->resolve($field);
            $name = $field instanceof DictionaryType ? $this->fieldName($field) : $this->values->text($field);
            if ($name === null || $name === '') {
                throw new ReadingException('A form action Fields entry must identify a named field by string or dictionary reference.');
            }
            $names[] = $name;
        }
        return array_values(array_unique($names));
    }
    /** @return array{int|float, int|float, int|float, int|float} */
    private function rectangle(?BasicType $value): array
    {
        $array = $this->numericArray($value);
        if ($array === null || count($array) !== 4) {
            throw new ReadingException('An annotation requires its four-coordinate Rect.');
        }
        return [$array[0], $array[1], $array[2], $array[3]];
    }
    /** @return null|list<int|float> */
    private function numericArray(?BasicType $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $result = $this->values->native($value);
        if (!is_array($result)) {
            throw new ReadingException('Numeric source coordinates must form an array.');
        }
        foreach ($result as $number) {
            if (!is_int($number) && !is_float($number)) {
                throw new ReadingException('Numeric source coordinates must be numbers.');
            }
        }
        return array_values($result);
    }
}
