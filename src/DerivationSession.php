<?php

declare(strict_types=1);

namespace PDF\Html;

use PDF\Html\Attributes\AttributeDeriver;
use PDF\Html\Attributes\DerivedAttributes;
use PDF\Html\Attributes\MathMlLayout;
use PDF\Html\Forms\WidgetDeriver;
use PDF\Html\Forms\AppearanceRenderer;
use PDF\Html\Html\ContentModelRepair;
use PDF\Html\Html\Element;
use PDF\Html\Html\ExpansionText;
use PDF\Html\Html\PageContentIdentifiers;
use PDF\Html\Html\HtmlNode;
use PDF\Html\Html\SafetyFilter;
use PDF\Html\Html\Serializer;
use PDF\Html\Html\Text;
use PDF\Html\Model\AssociatedFile;
use PDF\Html\Model\AttributeObject;
use PDF\Html\Model\ContentGroup;
use PDF\Html\Model\GraphicContent;
use PDF\Html\Model\GraphicViewport;
use PDF\Html\Model\ImageContent;
use PDF\Html\Model\LinkAnnotation;
use PDF\Html\Model\MarkedContent;
use PDF\Html\Model\SourceNode;
use PDF\Html\Model\StructureElement;
use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\TextContent;
use PDF\Html\Model\Widget;
use PDF\Html\Script\ScriptDeriver;
use PDF\Html\Script\ScriptAssetEmitter;
use PDF\Html\Script\WidgetBinding;

/** The mutable traversal state belongs to precisely one source document. */
final class DerivationSession
{
    private readonly SafetyFilter $safety;
    private readonly AssetCollection $assets;
    private readonly RoleResolver $roles;
    private readonly AttributeDeriver $attributes;
    private readonly MathMlLayout $mathLayout;
    private readonly ClassName $className;
    private readonly PageContentIdentifiers $pageIdentifiers;
    /** @var list<Diagnostic> */
    private array $diagnostics = [];
    /** @var array<string, string> */
    private array $identifiers = [];
    /** @var array<string, string> */
    private array $sourceIdentifiers = [];
    /** @var array<string, string> */
    private array $destinationAliases = [];
    /** @var array<int, array{Element, list<string>}> Retain cell identity until output references are resolved. */
    private array $tableHeaderTargets = [];
    /** @var array<string, true> */
    private array $usedIds = [];
    /** @var array<int, true> */
    private array $pages = [];
    /** @var list<Widget> */
    private array $widgets = [];
    /** @var list<Widget> */
    private array $renderedWidgets = [];
    /** @var list<WidgetBinding> */
    private array $widgetBindings = [];
    /** @var list<HtmlNode> */
    private array $extraHead = [];
    private int $nodeCount = 0;
    private int $sourceBytes = 0;
    private string $css = '';
    private readonly ?AppearanceRenderer $appearanceRenderer;

    public function __construct(
        private readonly TaggedDocument $document,
        private readonly DerivationPolicy $policy,
        private readonly DerivationLimits $limits,
        private readonly ?ContentRenderer $renderer,
        ?AppearanceRenderer $appearanceRenderer = null,
    ) {
        $this->safety = new SafetyFilter($policy, $limits);
        $this->assets = new AssetCollection($limits);
        $this->appearanceRenderer = $appearanceRenderer ?? ($renderer === null ? null : new \PDF\Html\Rendering\ContentAppearanceRenderer($renderer, $limits, $this->assets));
        $this->roles = new RoleResolver($document->roleMappings, $limits, $policy);
        $this->attributes = new AttributeDeriver();
        $this->mathLayout = new MathMlLayout($limits);
        $this->className = new ClassName();
        $this->pageIdentifiers = new PageContentIdentifiers($limits);
    }

    public function derive(): DerivationResult
    {
        foreach ($this->document->sourceDiagnostics as $message) {
            $this->diagnostics[] = new Diagnostic('source-interpretation', $message);
        }
        $this->measure([$this->document->filename, $this->document->title, $this->document->language, $this->document->classMap, $this->document->roleMappings, $this->document->documentScripts, $this->document->pageScripts, $this->document->associatedFiles]);
        foreach ($this->document->structure as $element) {
            $this->index($element, 0);
        }
        $nextIdentifier = 1;
        foreach ($this->identifiers as $key => $identifier) {
            if ($identifier === '') {
                do {
                    $identifier = 'pdf-se-' . $nextIdentifier++;
                } while (isset($this->usedIds[$identifier]));
                $this->identifiers[$key] = $identifier;
                $this->usedIds[$identifier] = true;
            }
        }
        $this->classStyles();
        $body = new Element('body');
        if ($this->document->permissions !== null) {
            $body->attributes['data-pdf-perms'] = (string) $this->document->permissions->mask;
            $this->diagnostics[] = new Diagnostic('viewer-permissions', 'The receiving viewer must enforce the authenticated PDF permissions; HTML alone cannot enforce them.');
        }
        foreach ($this->document->structure as $element) {
            array_push($body->children, ...$this->structure($element, [], null));
        }
        if ($this->document->hasAcroForm || $this->renderedWidgets !== []) {
            array_unshift($body->children, new Element('form', ['name' => 'acroform', 'id' => 'acroform_id']));
        }
        $navigation = new Element('nav', ['hidden' => '', 'id' => 'PDF-PageNavigation', 'role' => 'doc-pagelist']);
        foreach ($this->pages as $page => $_) {
            $navigation->children[] = new Element('a', ['href' => '#PDF-Page-' . $page], [new Text($this->document->pageLabels[$page] ?? (string) $page)]);
        }
        array_unshift($body->children, $navigation);
        $this->mathLayout->protectClassStyles($body);
        $this->safety->filter($body);
        if ($this->policy->allowActiveContent || $this->policy->formActions->enabled()) {
            $scripts = new ScriptDeriver($this->policy->allowActiveContent, $this->policy->formActions);
            $script = $scripts->derive($this->document, $this->renderedWidgets, $this->widgetBindings);
            foreach ($scripts->diagnostics as $message) {
                $this->diagnostics[] = new Diagnostic('script-profile', $message);
            }
            if ($script !== null && $script !== '') {
                $body->children[] = new Element('script', ['type' => 'text/javascript'], [new Text($script)]);
            }
        }
        if (!$this->policy->allowActiveContent && $this->hasScripts()) {
            $this->diagnostics[] = new Diagnostic('scripts-disabled', 'PDF ECMAScript was not derived in the safe profile; use an explicitly trusted active-content profile for section 4.5 and Annex B.');
        }
        $head = new Element('head', [], [
            new Element('title', [], [new Text($this->document->title ?? pathinfo($this->document->filename, PATHINFO_FILENAME))]),
            new Element('meta', ['charset' => 'UTF-8']),
            new Element('meta', ['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1']),
            new Element('style', [], [new Text($this->css)]),
        ]);
        $this->catalogAssociations($head);
        array_push($head->children, ...$this->extraHead);
        $this->safety->filter($head);
        $language = $this->document->language;
        $html = new Element('html', $language === null ? [] : ['lang' => $language], [$head, $body]);
        $repair = new ContentModelRepair($this->limits);
        $repaired = $repair->repair($html);
        foreach ($repair->diagnostics as $message) {
            $this->diagnostics[] = new Diagnostic('html-content-model-adaptation', $message);
        }
        $root = $repaired[0] ?? null;
        if (!$root instanceof Element || $root->tag !== 'html') {
            throw new DerivationException('Content model repair must preserve the HTML document root.');
        }
        (new ScriptAssetEmitter($this->assets, $this->limits))->emit($root);
        $this->validateOutputIdentifiers($root);
        $serialized = "<!DOCTYPE html>\n" . (new Serializer($this->limits))->serialize($root) . "\n";
        if (strlen($serialized) > $this->limits->maximumOutputBytes) {
            throw new DerivationException('The derived HTML output byte limit was exceeded.');
        }
        foreach ($this->pageIdentifiers->diagnostics as $message) {
            $this->diagnostics[] = new Diagnostic('page-content-identifier', $message);
        }
        return new DerivationResult($serialized, $this->css, $this->assets->all(), [...$this->diagnostics, ...$this->safety->diagnostics]);
    }

    private function index(SourceNode $node, int $depth): void
    {
        if (++$this->nodeCount > $this->limits->maximumNodes || $depth > $this->limits->maximumDepth) {
            throw new DerivationException('The source structure tree limit was exceeded.');
        }
        if ($node instanceof StructureElement) {
            if ($node->key === '' || isset($this->identifiers[$node->key])) {
                throw new DerivationException('Structure element keys must be nonempty and unique.');
            }
            $id = $this->effectiveAttributes($node)->html['id'] ?? $node->properties->id;
            if ($id !== null && ($id === '' || preg_match('/\s/u', $id) === 1 || isset($this->usedIds[$id]) || $id === 'acroform_id' || $id === 'PDF-PageNavigation' || preg_match('/\APDF-Page-[0-9]+\z/D', $id) === 1)) {
                throw new DerivationException('Structure IDs must be unique and cannot use reserved navigation/form identifiers.');
            }
            $this->identifiers[$node->key] = $id ?? '';
            if ($node->properties->id !== null) {
                if (isset($this->sourceIdentifiers[$node->properties->id])) {
                    throw new DerivationException('Source structure identifiers must be unique before HTML attribute overrides.');
                }
                $this->sourceIdentifiers[$node->properties->id] = $id ?? '';
            }
            if ($id !== null) {
                $this->usedIds[$id] = true;
            }
            $this->measure([$node->type, $node->namespace, $node->properties, $node->attributes]);
            foreach ($node->associatedFiles as $file) {
                $this->measure($file->bytes);
            }
            foreach ($node->children as $child) {
                $this->index($child, $depth + 1);
            }
        } elseif ($node instanceof MarkedContent || $node instanceof ContentGroup) {
            if ($node instanceof MarkedContent) {
                $this->measure($node->properties);
            }
            foreach ($node->children as $child) {
                $this->index($child, $depth + 1);
            }
        } elseif ($node instanceof Widget) {
            $this->widgets[] = $node;
            $this->measure($node->field);
            $this->measure($node->actions);
        } elseif ($node instanceof TextContent) {
            $this->measure($node->text);
        } elseif ($node instanceof ImageContent) {
            if (strlen($node->bytes ?? '') > $this->limits->maximumAssetBytes) {
                throw new DerivationException('An image exceeds the asset byte limit.');
            }
        } elseif ($node instanceof GraphicContent) {
            $this->measure($node->instructions);
        } elseif ($node instanceof LinkAnnotation) {
            $this->measure([$node->uri, $node->structureTarget]);
        }
    }

    private function measure(mixed $value, int $depth = 0): void
    {
        if ($depth > $this->limits->maximumDepth) {
            throw new DerivationException('Attribute nesting limit exceeded.');
        }
        if (is_string($value)) {
            $this->sourceBytes += strlen($value);
        } elseif (is_array($value) || is_object($value)) {
            foreach ((is_array($value) ? $value : get_object_vars($value)) as $part) {
                $this->measure($part, $depth + 1);
            }
        }
        if ($this->sourceBytes > $this->limits->maximumOutputBytes) {
            throw new DerivationException('The cumulative source text and attribute byte limit was exceeded.');
        }
    }

    private function classStyles(): void
    {
        foreach ($this->document->classMap as $name => $attributes) {
            $derived = $this->attributes->derive($attributes);
            $label = $this->attributes->derive($this->withoutPlacement($attributes));
            $selector = '.' . $this->className->encode($name);
            $common = $label->css;
            if ($label->layoutDisplay) {
                unset($common['display']);
                $this->css .= $this->mathLayout->classSelector($selector) . '{display:' . $label->css['display'] . "}\n";
            }
            $this->css .= $selector . '{' . $this->safety->cssText($this->safety->css($common)) . "}\n";
            $placement = array_diff_assoc($derived->css, $label->css);
            if ($placement !== []) {
                $placementSelector = $selector . ':where(:not([data-pdf-list-label]))';
                if ($derived->layoutDisplay && isset($placement['display'])) {
                    $this->css .= $this->mathLayout->classSelector($placementSelector) . '{display:' . $placement['display'] . "}\n";
                    unset($placement['display']);
                }
                if ($placement !== []) {
                    $this->css .= $placementSelector . '{' . $this->safety->cssText($this->safety->css($placement)) . "}\n";
                }
            }
        }
    }

    /**
     * @param list<string> $ancestors
     * @return list<HtmlNode>
     */
    private function structure(StructureElement $source, array $ancestors, ?string $listKind, ?string $formLabelTarget = null): array
    {
        $role = $this->roles->resolve($source);
        $type = $role->type;
        if (in_array($type, ['Private', 'Artifact'], true)) {
            return [];
        }
        if ($type === 'Annot') {
            $this->diagnostics[] = new Diagnostic('annotation-out-of-scope', 'Annot is outside Deriving HTML 1.2.', $source->key);
            return [];
        }
        if ($type === 'Note') {
            $this->diagnostics[] = new Diagnostic('non-wtpdf-note', 'Note is prohibited in the Well-Tagged PDF target; no FENote semantics were inferred.', $source->key);
        }
        if ($type === 'RB') {
            $this->diagnostics[] = new Diagnostic('obsolete-ruby-base', 'RB uses span because rb is obsolete in the HTML Living Standard; the original PDF structure type is retained.', $source->key);
        }
        $parent = $ancestors === [] ? null : $ancestors[array_key_last($ancestors)];
        $listLabel = $type === 'Lbl' && $parent === 'LI';
        $derived = $this->effectiveAttributes($source, $listLabel);
        if ($role->namespace === 'http://www.w3.org/1998/Math/MathML') {
            $derived = $this->mathLayout->adapt($derived, $type === 'math');
        }
        foreach ($derived->diagnostics as $message) {
            $this->diagnostics[] = new Diagnostic('attribute-adaptation', $message, $source->key);
        }
        $inline = $this->inlineContext($ancestors);
        $kind = $type === 'L' ? $this->listKind($derived->listNumbering) : ($type === 'TOC' ? 'ol' : $listKind);
        $tag = $this->tag($type, $role->namespace, $parent, $ancestors, $source, $kind);
        $attrs = ['id' => $this->identifiers[$source->key]];
        if ($listLabel) {
            $attrs['data-pdf-list-label'] = '';
        }
        if ($role->namespace !== 'http://www.w3.org/1998/Math/MathML' && $role->namespace !== 'http://www.w3.org/1999/xhtml') {
            $attrs['data-pdf-se-type'] = $type;
            if ($role->original !== []) {
                $attrs['data-pdf-se-type-original'] = implode(' ', $role->original);
            } elseif ($source->namespace === null) {
                $attrs['data-pdf-se-type-original'] = $source->type;
            }
        }
        $properties = $source->properties;
        if ($properties->language !== null && $properties->language !== '') {
            $attrs['lang'] = $properties->language;
        }
        if ($properties->classes !== []) {
            $attrs['class'] = implode(' ', array_map($this->className->encode(...), $properties->classes));
        }
        if ($properties->expansion !== null && $properties->expansion !== '') {
            $attrs['title'] = $properties->expansion;
        }
        if ($derived->noteType !== null && $type === 'FENote') {
            $attrs['data-pdf-fenotetype'] = $derived->noteType;
        }
        if (preg_match('/\AH([7-9]|[1-9][0-9]+)\z/D', $type, $heading) === 1) {
            $attrs['role'] = 'heading';
            $attrs['aria-level'] = $heading[1];
        }
        $element = new Element($tag, $attrs);
        if ($type === 'LBody' && $kind !== 'dl') {
            $element->attributes['style'] = 'display:inline';
        }
        $css = $derived->css;
        if (in_array($type, ['L', 'TOC'], true) && $this->hasExplicitLabels($source)) {
            $css['list-style-type'] = 'none';
        }
        $htmlAttributes = $derived->html;
        if ($derived->tableHeaders !== null) {
            $headers = [];
            foreach ($derived->tableHeaders as $header) {
                if (isset($this->sourceIdentifiers[$header])) {
                    $headers[] = $this->sourceIdentifiers[$header];
                } else {
                    $this->diagnostics[] = new Diagnostic('unresolved-table-header', 'A source table-header identifier has no derived structure target.', $header);
                }
            }
            $htmlAttributes['headers'] = implode(' ', array_unique($headers));
            $this->tableHeaderTargets[spl_object_id($element)] = [$element, $headers];
        }
        $this->applyAttributes($element, $htmlAttributes, $css);
        if ($type === 'Lbl' && $tag === 'label' && $formLabelTarget !== null) {
            $element->attributes['for'] = $formLabelTarget;
        }
        if ($role->namespace === 'http://www.w3.org/1999/xhtml' && $tag === 'script' && $properties->actualText === null) {
            $scripts = [];
            foreach ($source->associatedFiles as $file) {
                if (in_array(strtolower($file->mediaType), ['text/javascript', 'application/javascript'], true)) {
                    foreach ($this->associated($file) ?? [] as $script) {
                        if ($script instanceof Element) {
                            $script->attributes = [...$element->attributes, ...$script->attributes];
                            if ($scripts !== []) {
                                unset($script->attributes['id']);
                            }
                            $scripts[] = $script;
                        }
                    }
                }
            }
            if ($scripts !== []) {
                return $scripts;
            }
        }
        if ($type === 'Link' || $type === 'Reference') {
            $target = $this->linkTarget($source);
            if ($target !== null && !array_key_exists('href', $derived->html)) {
                $element->attributes['href'] = $target;
            }
        }
        $widget = $type === 'Form' && !$derived->printField && $properties->actualText === null ? $this->firstWidget($source) : null;
        if ($widget !== null) {
            return $this->interactiveForm($source, $widget, $ancestors, $kind, $derived);
        }
        $nextAncestors = [...$ancestors, $type];
        // Each Form owns its labels; a non-interactive nested Form has no field target.
        $nextFormLabelTarget = $type === 'Form' ? null : $formLabelTarget;
        $hasMath = false;
        if ($type === 'Formula') {
            foreach ($source->children as $child) {
                if ($child instanceof StructureElement) {
                    $childRole = $this->roles->resolve($child);
                    if ($childRole->type === 'math' && $childRole->namespace === 'http://www.w3.org/1998/Math/MathML') {
                        $hasMath = true;
                        break;
                    }
                }
            }
        }
        $association = $properties->actualText === null && !($type === 'Formula' && $hasMath)
            ? $this->associationPlan($source, $element, $derived) : new AssociationPlan();
        if ($association->alternative) {
            $nodes = $association->nodes;
            $target = null;
            foreach ($nodes as $node) {
                if ($node instanceof Element && isset($node->attributes['id'])) {
                    $target = $node->attributes['id'];
                    break;
                }
            }
            $sourceId = $element->attributes['id'];
            if ($target === null) {
                // Alternatives may be plain text, a head import or a stylesheet/script only.
                // Retain the structure destination at this exact replacement position.
                array_unshift($nodes, new Element('span', ['id' => $sourceId]));
            } elseif ($target !== $sourceId) {
                $this->destinationAliases[$sourceId] = $target;
            }
            return [...$association->before, ...$this->pageIdentifiers->identify($this->replacementPageAnchors($source->children), $nodes), ...$association->after];
        }
        $children = [];
        $visualFigure = $type === 'Figure' && $this->policy->preferVisualFigures && $properties->actualText === null && !$association->supplement;
        if ($visualFigure && (!$this->policy->includeGraphics || $this->renderer === null)) {
            throw new DerivationException('The visual-figure profile requires an explicitly supplied ContentRenderer and enabled graphics.');
        }
        $box = $visualFigure ? $this->boundingBox($source) : null;
        $groupImage = $visualFigure ? $this->renderer?->render(new ContentGroup($source->children, viewport: $box === null ? null : new GraphicViewport(...$box))) : null;
        if ($visualFigure && $groupImage === null) {
            throw new DerivationException('The rendering engine did not produce the required grouped figure image.');
        }
        if ($properties->actualText !== null) {
            $children = $this->pageIdentifiers->identify($this->replacementPageAnchors($source->children), [new Text($properties->actualText)]);
        } elseif ($groupImage !== null) {
            $children = $this->pageIdentifiers->identify($this->replacementPageAnchors($source->children), [$this->image($groupImage)]);
        } else {
            $inserted = false;
            foreach ($source->children as $child) {
                if ($role->namespace === 'http://www.w3.org/1999/xhtml' && $child instanceof Widget) {
                    $this->diagnostics[] = new Diagnostic('html-namespace-widget', 'HTML-namespace fields use their explicit structure attributes; their PDF widget mapping was not applied.', $source->key);
                    continue;
                }
                if ($type === 'Form' && $derived->printField && $child instanceof Widget) {
                    $markers = $this->pageAnchor($child->page);
                    $appearance = [];
                    if ($this->appearanceRenderer !== null && $child->appearance !== []) {
                        $appearance = $this->appearanceRenderer->render($child->appearance);
                    } else {
                        $appearance[] = new Text($child->caption ?? $child->field->name);
                        $this->diagnostics[] = new Diagnostic('print-field-appearance', 'A non-interactive PrintField has a textual fallback; provide AppearanceRenderer for its visual appearance.', $source->key);
                    }
                    array_push($children, ...$this->pageIdentifiers->identify($markers, $appearance));
                    continue;
                }
                if ($association->supplement && !$child instanceof StructureElement) {
                    if (!$inserted) {
                        array_push($children, ...$association->nodes);
                        $inserted = true;
                    }
                    continue;
                }
                array_push($children, ...$this->content($child, $nextAncestors, $kind, $nextFormLabelTarget));
            }
            if ($association->supplement && !$inserted) {
                array_push($children, ...$association->nodes);
            }
        }
        if ($type === 'Code' && $this->hasChildType($source, 'Sub') && $properties->actualText === null) {
            $lines = [];
            foreach ($children as $child) {
                if ($child instanceof Element && $child->tag === 'code' && $lines !== []) {
                    $lines[] = new Text("\n");
                }
                $lines[] = $child;
            }
            $children = $lines;
        }
        $element->children = $children;
        if ($properties->actualText === null) {
            if ($type === 'Figure' || ($type === 'Form' && $derived->printField)) {
                $this->imageProperties($element, $derived->html['alt'] ?? $properties->alternateDescription, $element->attributes);
            } elseif ($type === 'Formula' || $role->namespace === 'http://www.w3.org/1998/Math/MathML') {
                if ($properties->alternateDescription !== null) {
                    $this->mathProperties($element, $properties->alternateDescription);
                }
            }
        }
        if ($properties->expansion !== null && $properties->expansion !== '') {
            $element->tag = 'abbr';
        }
        if ($derived->textPosition !== null) {
            $element->children = [new Element($derived->textPosition, [], $element->children)];
        }
        if ($type === 'Figure' && $inline && $properties->actualText === null && ($properties->expansion === null || $properties->expansion === '')) {
            $output = [];
            foreach ($element->children as $child) {
                if ($child instanceof Text && $child->value !== '') {
                    $child = new Element('span', ['data-pdf-graphic-text' => ''], [$child]);
                }
                if ($child instanceof Element) {
                    if (!in_array($child->tag, ['a', 'img'], true)) {
                        $child->tag = 'span';
                    }
                    foreach ($derived->html as $name => $value) {
                        if (!in_array($name, ['id', 'lang', 'alt', 'title'], true)) {
                            $child->attributes[$name] = $value;
                        }
                    }
                    $this->mergeStyle($child, $css);
                }
                $output[] = $child;
            }
            $identified = false;
            foreach ($output as $child) {
                if ($child instanceof Element && $this->identifyFigureContent($child, $attrs)) {
                    $identified = true;
                    break;
                }
            }
            if (!$identified) {
                foreach ($output as $index => $child) {
                    if ($child instanceof Element && $child->children !== []
                        && ($child->attributes['role'] ?? '') !== 'doc-pagebreak'
                        && ($child->attributes['data-pdf-se-type'] ?? '') !== 'Caption') {
                        $contentAttrs = $element->attributes;
                        unset($contentAttrs['alt']);
                        $alt = $derived->html['alt'] ?? $properties->alternateDescription;
                        if ($alt !== null) {
                            $contentAttrs['role'] = $derived->html['role'] ?? 'img';
                            $contentAttrs['aria-label'] = $derived->html['aria-label'] ?? $alt;
                            $this->diagnostics[] = new Diagnostic('figure-text-alt-adaptation', 'A textual inline figure uses an atomic named group with role=img and aria-label; HTML span cannot carry alt.', $source->key);
                        }
                        $output[$index] = new Element('span', $contentAttrs, [$child]);
                        $identified = true;
                        break;
                    }
                }
                if (!$identified) {
                    array_unshift($output, new Element('span', ['id' => $attrs['id']]));
                }
            }
            return [...$association->before, ...$output, ...$association->after];
        }
        if ($type === 'NonStruct' && $derived->html === [] && $derived->css === []) {
            return [...$association->before, new Element('span', ['id' => $attrs['id']]), ...$element->children, ...$association->after];
        }
        if (in_array($type, ['L', 'TOC'], true)) {
            $captions = [];
            $remaining = [];
            foreach ($element->children as $child) {
                if ($child instanceof Element && ($child->attributes['data-pdf-se-type'] ?? '') === 'Caption') {
                    $child->tag = 'div';
                    $captions[] = $child;
                } else {
                    $remaining[] = $child;
                }
            }
            $element->children = $remaining;
            $list = [...$captions, $element];
            if (in_array($parent, ['L', 'TOC'], true) && $listKind !== 'dl') {
                return [...$association->before, new Element('li', ['style' => 'list-style-type:none'], $list), ...$association->after];
            }
            return [...$association->before, ...$list, ...$association->after];
        }
        if ($type === 'Reference') {
            $this->flattenAnchors($element);
        }
        return [...$association->before, $element, ...$association->after];
    }

    /**
     * @param list<string> $ancestors
     * @return list<HtmlNode>
     */
    private function content(SourceNode $source, array $ancestors, ?string $listKind, ?string $formLabelTarget = null): array
    {
        if ($source instanceof StructureElement) {
            return $this->structure($source, $ancestors, $listKind, $formLabelTarget);
        }
        if ($source instanceof LinkAnnotation) {
            return [];
        }
        if ($source instanceof MarkedContent && $source->artifact) {
            return [];
        }
        $page = property_exists($source, 'page') ? $source->page : null;
        $markers = $this->pageAnchor(is_int($page) ? $page : null);
        $output = [];
        if ($source instanceof TextContent) {
            $output[] = new Text($source->text);
        } elseif ($source instanceof MarkedContent) {
            array_push($output, ...$this->marked($source, $ancestors, $listKind, $formLabelTarget));
        } elseif ($source instanceof ImageContent) {
            $output[] = $this->image($source);
        } elseif ($source instanceof ContentGroup) {
            foreach ($source->children as $child) {
                array_push($output, ...$this->content($child, $ancestors, $listKind, $formLabelTarget));
            }
        } elseif ($source instanceof GraphicContent) {
            $image = $this->policy->includeGraphics ? $this->renderer?->render($source) : null;
            if ($image !== null) {
                $output[] = $this->image($image);
            } elseif (str_starts_with($source->kind, 'unsupported-image')) {
                $output[] = $this->image(new ImageContent(null, 'image/png', $source->widthPoints, $source->heightPoints));
            } else {
                $this->diagnostics[] = new Diagnostic('graphic-omitted', 'Content was omitted; provide ContentRenderer when it matters to this reuse application: ' . $source->instructions, $source->kind);
            }
        } elseif ($source instanceof Widget) {
            $rendered = (new WidgetDeriver($this->appearanceRenderer, formActions: $this->policy->formActions))->derive($source, 'pdf-widget-' . $this->widgetIndex($source));
            $this->renderedWidgets[] = $source;
            $this->widgetBindings[] = new WidgetBinding($source, $rendered->field->attributes['id']);
            foreach ($rendered->diagnostics as $message) {
                $this->diagnostics[] = new Diagnostic('widget-adaptation', $message);
            }
            array_push($output, ...$rendered->nodes);
        } else {
            throw new DerivationException('Unsupported source content object.');
        }
        return $this->pageIdentifiers->identify($markers, $output);
    }

    /**
     * @param list<string> $ancestors
     * @return list<HtmlNode>
     */
    private function marked(MarkedContent $source, array $ancestors, ?string $listKind, ?string $formLabelTarget): array
    {
        $p = $source->properties;
        $children = [];
        if ($p->actualText !== null) {
            $children[] = new Text($p->actualText);
        } else {
            foreach ($source->children as $child) {
                array_push($children, ...$this->content($child, $ancestors, $listKind, $formLabelTarget));
            }
        }
        if ($p->expansion !== null) {
            $children = [new Element('abbr', ['title' => $p->expansion], $children)];
        }
        $attrs = [];
        if ($p->language !== null) {
            $attrs['lang'] = $p->language;
        }
        if ($p->alternateDescription !== null) {
            $attrs['role'] = 'img';
            $attrs['aria-label'] = $p->alternateDescription;
            $this->diagnostics[] = new Diagnostic('marked-alt-adaptation', 'Marked-content Alt is represented as an atomic named group with role=img and aria-label instead of invalid span[alt].');
        }
        // 4.4.7.5 requires one span for combined properties, including ActualText + E.
        $combined = $p->actualText !== null && $p->expansion !== null;
        return $attrs === [] && !$combined ? $children : [new Element('span', $attrs, $children)];
    }

    private function image(ImageContent $source): Element
    {
        if (!is_finite($source->widthPoints) || !is_finite($source->heightPoints) || $source->widthPoints < 0 || $source->heightPoints < 0) {
            throw new DerivationException('Image logical dimensions must be finite and nonnegative.');
        }
        $bytes = $source->bytes;
        $type = $source->mediaType;
        $valid = $bytes !== null && in_array($type, ['image/png', 'image/jpeg', 'image/gif'], true);
        if ($valid) {
            $info = @getimagesizefromstring($bytes);
            $valid = is_array($info) && $info['mime'] === $type;
        }
        if (!$valid) {
            $bytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
            $type = 'image/gif';
            $this->diagnostics[] = new Diagnostic('image-placeholder', 'An image could not be converted; a placeholder preserves its logical display size.');
        }
        if ($bytes === false || $bytes === null) {
            throw new DerivationException('The fallback image bytes could not be prepared.');
        }
        $filename = 'image-' . hash('sha256', $bytes) . match ($type) {
            'image/png' => '.png', 'image/jpeg' => '.jpg', default => '.gif'
        };
        $this->assets->add($filename, $type, $bytes);
        return new Element('img', ['src' => 'data:' . $type . ';base64,' . base64_encode($bytes), 'width' => (string) max(1, (int) round($source->widthPoints * 4 / 3)), 'height' => (string) max(1, (int) round($source->heightPoints * 4 / 3)), 'alt' => '']);
    }

    private function effectiveAttributes(StructureElement $source, bool $ignorePlacement = false): DerivedAttributes
    {
        $classes = [];
        foreach ($source->properties->classes as $name) {
            array_push($classes, ...($this->document->classMap[$name] ?? []));
        }
        // An A value replaces the same owner/key from C before owner precedence is applied.
        $combined = $classes;
        foreach ($source->attributes as $direct) {
            foreach ($combined as $index => $attribute) {
                if ($attribute->owner === $direct->owner && $attribute->namespaceIdentity === $direct->namespaceIdentity) {
                    $values = array_diff_key($attribute->values, $direct->values);
                    $combined[$index] = new AttributeObject($attribute->owner, $values, $attribute->namespace, $attribute->namespaceIdentity);
                }
            }
            $combined[] = $direct;
        }
        return $this->attributes->derive($ignorePlacement ? $this->withoutPlacement($combined) : $combined, $source->namespace, $source->namespaceIdentity);
    }

    /**
     * @param list<AttributeObject> $attributes
     * @return list<AttributeObject>
     */
    private function withoutPlacement(array $attributes): array
    {
        return array_map(static function (AttributeObject $attribute): AttributeObject {
            if ($attribute->owner !== 'Layout') {
                return $attribute;
            }
            $values = $attribute->values;
            unset($values['Placement']);
            return new AttributeObject($attribute->owner, $values, $attribute->namespace, $attribute->namespaceIdentity);
        }, $attributes);
    }

    /**
     * @param array<string, string> $html
     * @param array<string, string> $css
     */
    private function applyAttributes(Element $element, array $html, array $css): void
    {
        $element->attributes = [...$element->attributes, ...$html];
        $this->mergeStyle($element, $css);
    }

    /**
     * @param array<string, string> $css
     */
    private function mergeStyle(Element $element, array $css): void
    {
        $existing = [];
        foreach (explode(';', $element->attributes['style'] ?? '') as $part) {
            $pair = explode(':', $part, 2);
            if (count($pair) === 2) {
                $existing[trim($pair[0])] = trim($pair[1]);
            }
        }
        $style = $this->safety->cssText($this->safety->css([...$existing, ...$css]));
        if ($style !== '') {
            $element->attributes['style'] = $style;
        }
    }

    /**
     * @param list<string> $ancestors
     */
    private function tag(string $type, ?string $namespace, ?string $parent, array $ancestors, StructureElement $source, ?string $listKind): string
    {
        if ($namespace === 'http://www.w3.org/1998/Math/MathML' || $namespace === 'http://www.w3.org/1999/xhtml') {
            $tag = strtolower($type);
            if (!$this->safety->tagAllowed($tag, $namespace)) {
                throw new DerivationException('An unsafe or unsupported explicit HTML/MathML element was requested: ' . $tag);
            }
            return $tag;
        }
        $inline = $this->inlineContext($ancestors);
        if (preg_match('/\AH([1-9][0-9]*)\z/D', $type, $match) === 1) {
            return (int) $match[1] > 6 || in_array('TH', $ancestors, true) ? 'p' : 'h' . $match[1];
        }
        return match ($type) {
            'Art' => 'article', 'Aside' => 'aside', 'BibEntry', 'P', 'H', 'Note' => 'p', 'BlockQuote' => 'blockquote',
            'Document', 'DocumentFragment', 'Div', 'Part', 'Title', 'NonStruct' => 'div',
            'Em' => 'em', 'Strong' => 'strong', 'Span', 'Sub', 'Warichu', 'WT', 'WP' => $type === 'Sub' && $parent === 'Code' ? 'code' : 'span',
            'Index' => 'section', 'Sect' => in_array('TH', $ancestors, true) ? 'div' : 'section', 'Quote' => 'q',
            'Link', 'Reference' => 'a', 'Ruby' => 'ruby', 'RB' => 'span', 'RP' => 'rp', 'RT' => 'rt',
            'Table' => 'table', 'TBody' => 'tbody', 'TD' => 'td', 'TFoot' => 'tfoot', 'TH' => 'th', 'THead' => 'thead', 'TR' => 'tr',
            'TOC' => 'ol', 'TOCI' => 'li', 'L' => $listKind ?? 'ul', 'LI' => $listKind === 'dl' ? 'div' : 'li', 'LBody' => $listKind === 'dl' ? 'dd' : 'div',
            'Lbl' => $this->labelTag($source, $parent, $ancestors, $listKind),
            'Caption' => $parent === 'Figure' && !$inline ? 'figcaption' : ($parent === 'Table' ? 'caption' : 'div'),
            'FENote', 'Formula' => $inline ? 'span' : 'div', 'Figure', 'Form' => 'figure',
            'Code' => $this->hasChildType($source, 'Sub') ? 'pre' : 'code',
            default => throw new DerivationException('Unmapped PDF standard structure type: ' . $type),
        };
    }

    /**
     * @param list<string> $ancestors
     */
    private function inlineContext(array $ancestors): bool
    {
        foreach ($ancestors as $type) {
            if (in_array($type, ['P', 'Sub', 'Span', 'Em', 'Strong', 'Quote'], true) || preg_match('/\AH[0-9]+\z/D', $type) === 1) {
                return true;
            }
        }
        return false;
    }

    private function listKind(?string $numbering): string
    {
        return $numbering === 'Description' ? 'dl' : (in_array($numbering, ['Ordered', 'Decimal', 'UpperRoman', 'LowerRoman', 'UpperAlpha', 'LowerAlpha'], true) ? 'ol' : 'ul');
    }

    private function hasChildType(StructureElement $source, string $type): bool
    {
        foreach ($source->children as $child) {
            if ($child instanceof StructureElement && $this->roles->resolve($child)->type === $type) {
                return true;
            }
        }
        return false;
    }

    private function hasBlockChildren(StructureElement $source): bool
    {
        foreach ($source->children as $child) {
            if (!$child instanceof StructureElement) {
                continue;
            }
            $role = $this->roles->resolve($child);
            if ($role->namespace === 'http://www.w3.org/1999/xhtml') {
                if ((new ContentModelRepair($this->limits))->block(strtolower($role->type))) {
                    return true;
                }
                continue;
            }
            if (in_array($role->type, ['Art', 'Aside', 'BibEntry', 'BlockQuote', 'Document', 'DocumentFragment', 'Div', 'Part', 'Title', 'Index', 'Sect', 'P', 'H', 'Note', 'FENote', 'Figure', 'Formula', 'Form', 'Caption', 'L', 'LI', 'LBody', 'TOC', 'TOCI', 'Table'], true)
                || preg_match('/\AH[1-9][0-9]*\z/D', $role->type) === 1
                || $role->type === 'Code' && $this->hasChildType($child, 'Sub')) {
                return true;
            }
        }
        return false;
    }

    private function hasExplicitLabels(StructureElement $source): bool
    {
        foreach ($source->children as $child) {
            if ($child instanceof StructureElement && $this->roles->resolve($child)->type === 'LI' && isset($child->children[0]) && $child->children[0] instanceof StructureElement && $this->roles->resolve($child->children[0])->type === 'Lbl') {
                return true;
            }
        }
        return false;
    }

    private function linkTarget(StructureElement $source): ?string
    {
        $referenceRole = $this->roles->resolve($source)->type === 'Reference';
        foreach ($source->children as $child) {
            if ($referenceRole && $child instanceof StructureElement && $this->roles->resolve($child)->type === 'Link') {
                $target = $this->linkTarget($child);
                if ($target !== null) {
                    return $target;
                }
            }
            if ($child instanceof LinkAnnotation) {
                if ($child->structureTarget !== null) {
                    $target = $this->identifiers[$child->structureTarget] ?? null;
                    return $target === null ? null : '#' . rawurlencode($target);
                }
                return $child->uri ?? ($child->pageTarget === null ? null : '#PDF-Page-' . $child->pageTarget);
            }
        }
        $reference = $source->properties->references[0] ?? null;
        return $reference !== null && isset($this->identifiers[$reference]) ? '#' . rawurlencode($this->identifiers[$reference]) : null;
    }

    private function flattenAnchors(Element $element): void
    {
        foreach ($element->children as $child) {
            if ($child instanceof Element && $child->tag === 'a') {
                $child->tag = 'span';
                unset($child->attributes['href']);
            }
        }
    }

    /**
     * @param array<string, string> $attributes
     */
    private function imageProperties(Element $element, ?string $alt, array $attributes): void
    {
        foreach ($element->children as $child) {
            if ($child instanceof Element) {
                if ($child->tag === 'img') {
                    if ($alt !== null) {
                        $child->attributes['alt'] = $alt;
                    }
                    foreach (['lang', 'class', 'style'] as $name) {
                        if (isset($attributes[$name])) {
                            $child->attributes[$name] = $attributes[$name];
                        }
                    }
                } else {
                    $this->imageProperties($child, $alt, $attributes);
                }
            }
        }
    }

    /** @param array<string, string> $attributes */
    private function identifyFigureContent(Element $element, array $attributes): bool
    {
        if ($element->tag === 'img') {
            foreach (['id', 'data-pdf-se-type', 'data-pdf-se-type-original'] as $name) {
                if (isset($attributes[$name])) {
                    $element->attributes[$name] = $attributes[$name];
                }
            }
            return true;
        }
        foreach ($element->children as $child) {
            if ($child instanceof Element && $this->identifyFigureContent($child, $attributes)) {
                return true;
            }
        }
        return false;
    }

    private function mathProperties(Element $element, string $alt): void
    {
        if ($element->tag === 'math') {
            $element->attributes['alttext'] ??= $alt;
        }
        foreach ($element->children as $child) {
            if ($child instanceof Element) {
                $this->mathProperties($child, $alt);
            }
        }
    }

    private function firstWidget(StructureElement $source): ?Widget
    {
        foreach ($source->children as $child) {
            if ($child instanceof Widget) {
                return $child;
            }
        }
        return null;
    }

    private function widgetIndex(Widget $widget): int
    {
        foreach ($this->widgets as $i => $candidate) {
            if ($candidate === $widget) {
                return $i + 1;
            }
        }
        throw new DerivationException('A widget was not indexed in this document.');
    }

    /**
     * @param list<string> $ancestors
     * @return list<HtmlNode>
     */
    private function interactiveForm(StructureElement $source, Widget $widget, array $ancestors, ?string $kind, DerivedAttributes $derived): array
    {
        $id = $this->identifiers[$source->key];
        $rendering = (new WidgetDeriver($this->appearanceRenderer, formActions: $this->policy->formActions))->derive($widget, $id);
        $field = $rendering->field;
        foreach ($rendering->diagnostics as $message) {
            $this->diagnostics[] = new Diagnostic('widget-adaptation', $message, $source->key);
        }
        if ($source->properties->language !== null) {
            $field->attributes['lang'] = $source->properties->language;
        }
        if ($source->properties->classes !== []) {
            $field->attributes['class'] = implode(' ', array_map($this->className->encode(...), $source->properties->classes));
        }
        if ($source->properties->expansion !== null) {
            $field->attributes['title'] = $source->properties->expansion;
        }
        $field->attributes['data-pdf-se-type'] = 'Form';
        $role = $this->roles->resolve($source);
        if ($role->original !== []) {
            $field->attributes['data-pdf-se-type-original'] = implode(' ', $role->original);
        } elseif ($source->namespace === null) {
            $field->attributes['data-pdf-se-type-original'] = $source->type;
        }
        $this->applyAttributes($field, $derived->html, $derived->css);
        $output = [];
        foreach ($source->children as $child) {
            if ($child === $widget) {
                $this->renderedWidgets[] = $widget;
                $this->widgetBindings[] = new WidgetBinding($widget, $field->attributes['id'] ?? $id);
                $nodes = [];
                foreach ($rendering->nodes as $node) {
                    $nodes[] = $node === $field && $source->properties->expansion !== null
                        ? (new ExpansionText($this->limits))->wrap($field, $source->properties->expansion) : $node;
                }
                array_push($output, ...$this->pageIdentifiers->identify($this->pageAnchor($widget->page), $nodes));
            } else {
                array_push($output, ...$this->content($child, [...$ancestors, 'Form'], $kind, $field->attributes['id'] ?? $id));
            }
        }
        return $output;
    }

    /**
     * Deriving HTML 1.2, 4.3.5.4 and 4.3.5.11.1 (physical pages 28, 29 and 37).
     * @param list<string> $ancestors
     */
    private function labelTag(StructureElement $source, ?string $parent, array $ancestors, ?string $listKind): string
    {
        if (in_array('Form', $ancestors, true)) {
            return $this->hasFormLabelBlocks($source) ? 'div' : 'label';
        }
        if ($parent === 'LI' && $listKind === 'dl') {
            return 'dt';
        }
        if ($parent === 'LI' || $parent === 'Caption' || $parent === 'TOCI'
            || $parent !== null && preg_match('/\AH[1-9][0-9]*\z/D', $parent) === 1) {
            return $this->hasBlockChildren($source) ? 'div' : 'span';
        }
        return 'span';
    }

    private function associationPlan(StructureElement $source, Element $element, DerivedAttributes $derived): AssociationPlan
    {
        $supplement = false;
        $replacement = false;
        $associated = [];
        $before = [];
        $after = [];
        $selection = new AssociatedFileSelection($this->policy, $this->limits);
        foreach ($source->associatedFiles as $file) {
            if (!in_array($file->relationship, ['Alternative', 'Supplement'], true)) {
                continue;
            }
            $nodes = $this->associated($file);
            if ($nodes === null) {
                continue;
            }
            $replacement = $replacement || $selection->replaces($file);
            $supplement = $supplement || $selection->supplements($file);
            if (in_array(strtolower($file->mediaType), ['text/css', 'text/javascript', 'application/javascript'], true)) {
                if (strtolower($file->mediaType) === 'text/css') {
                    array_push($before, ...$nodes);
                } else {
                    array_push($after, ...$nodes);
                }
                continue;
            }
            foreach ($nodes as $node) {
                if ($node instanceof Element) {
                    $attrs = $element->attributes;
                    if ($node->tag === 'math') {
                        // The fragment owns its MathML attributes; inherited Layout CSS must not replace math layout.
                        unset($attrs['style']);
                        $inherited = new Element('math', $attrs);
                        $mathAttributes = $this->mathLayout->adapt($derived, true);
                        $this->applyAttributes($inherited, $mathAttributes->html, $mathAttributes->css);
                        $attrs = $inherited->attributes;
                    }
                    if ($associated !== [] || $file->relationship === 'Supplement') {
                        unset($attrs['id']);
                    }
                    $node->attributes = [...$attrs, ...$node->attributes];
                    if ($source->properties->alternateDescription !== null && $node->tag === 'img') {
                        $node->attributes['alt'] = $source->properties->alternateDescription;
                    }
                    if (strtolower($file->mediaType) === 'image/svg+xml' && $node->tag === 'img') {
                        $box = $this->boundingBox($source);
                        if ($box !== null) {
                            $node->attributes['width'] = (string) (int) round(abs($box[2] - $box[0]) * 4 / 3);
                            $node->attributes['height'] = (string) (int) round(abs($box[3] - $box[1]) * 4 / 3);
                        }
                    }
                }
                $associated[] = $node;
            }
        }
        return new AssociationPlan($associated, $after, $replacement, $supplement, $before);
    }

    /**
     * @return list<HtmlNode>|null
     */
    private function associated(AssociatedFile $file, bool $headContext = false): ?array
    {
        $type = strtolower($file->mediaType);
        $url = $file->url;
        if ($url !== null) {
            $scheme = parse_url($url, PHP_URL_SCHEME);
            $url = is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) ? $this->safety->url($url, true) : null;
            if ($url === null) {
                $this->diagnostics[] = new Diagnostic('associated-url-blocked', 'An associated URL was not allowed; no resource was fetched.', $file->filename);
                return null;
            }
        }
        if (in_array($type, ['text/html', 'application/xhtml+xml'], true)) {
            if ($url !== null) {
                $this->extraHead[] = new Element('link', ['rel' => 'import', 'href' => $url]);
                return [];
            }
            return $this->safety->fragment($file->bytes ?? '', false, $headContext);
        }
        if ($type === 'application/mathml+xml') {
            return $url === null ? $this->safety->fragment($file->bytes ?? '', true) : null;
        }
        if ($type === 'text/css') {
            if ($url !== null && $this->policy->allowRemoteResources) {
                return [new Element('link', ['rel' => 'stylesheet', 'href' => $url])];
            }
            if ($this->policy->allowActiveContent) {
                if (!$this->policy->allowRemoteResources && preg_match('/@import|url\s*\(|\\\\|\/\*/i', $file->bytes ?? '') === 1) {
                    $this->diagnostics[] = new Diagnostic('associated-css-resources-blocked', 'A stylesheet that may reference other resources needs an explicit remote-resource policy.', $file->filename);
                    return null;
                }
                $sheet = $this->safety->stylesheet($file->bytes ?? '');
                $name = $this->assets->add($file->filename, 'text/css', $sheet);
                return [new Element('link', ['rel' => 'stylesheet', 'type' => 'text/css', 'href' => $name])];
            }
            $this->diagnostics[] = new Diagnostic('associated-css-blocked', 'Associated stylesheets require an explicit active-content policy.', $file->filename);
            return null;
        }
        if (in_array($type, ['text/javascript', 'application/javascript'], true)) {
            if (!$this->policy->allowActiveContent) {
                $this->diagnostics[] = new Diagnostic('associated-script-blocked', 'Associated JavaScript was omitted by the safe profile.', $file->filename);
                return null;
            }
            return [new Element('script', $url === null ? ['type' => 'text/javascript'] : ['type' => 'text/javascript', 'src' => $url], $url === null ? [new Text($file->bytes ?? '')] : [])];
        }
        if (in_array($type, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            if ($url !== null) {
                return [new Element('img', ['src' => $url, 'alt' => ''])];
            }
            $info = @getimagesizefromstring($file->bytes ?? '');
            if (is_array($info) && $info['mime'] === $type) {
                // Preserve UF so other associated resources can refer to the original name.
                $this->assets->add($file->filename, $type, $file->bytes ?? '');
            }
            return [$this->image(new ImageContent($file->bytes, $type, is_array($info) ? $info[0] * 0.75 : 1, is_array($info) ? $info[1] * 0.75 : 1))];
        }
        if ($type === 'image/svg+xml') {
            if ($url !== null) {
                return [new Element('img', ['src' => $url, 'alt' => ''])];
            }
            if (!$this->policy->allowActiveContent) {
                $this->diagnostics[] = new Diagnostic('associated-svg-blocked', 'Associated SVG requires an explicit active-content policy.', $file->filename);
                return null;
            }
            $name = $this->assets->add($file->filename, $type, $file->bytes ?? '');
            return [new Element('img', ['src' => $name, 'alt' => ''])];
        }
        $this->diagnostics[] = new Diagnostic('associated-type-ignored', 'This associated-file media type has no derivation mapping.', $type);
        return null;
    }

    private function catalogAssociations(Element $head): void
    {
        foreach ($this->document->associatedFiles as $file) {
            if ($file->relationship === 'Supplement' && in_array(strtolower($file->mediaType), ['text/html', 'application/xhtml+xml'], true)) {
                $nodes = $this->associated($file, true);
                if ($nodes !== null) {
                    array_push($head->children, ...$nodes);
                }
                return;
            }
        }
        foreach ($this->document->associatedFiles as $file) {
            $nodes = $this->associated($file, true);
            if ($nodes !== null) {
                foreach ($nodes as $node) {
                    if ($node instanceof Element && in_array($node->tag, ['style', 'link', 'script', 'meta', 'title'], true)) {
                        $head->children[] = $node;
                    }
                }
            }
        }
    }

    private function hasScripts(): bool
    {
        if ($this->document->documentScripts !== [] || $this->document->pageScripts !== []) {
            return true;
        }
        foreach ($this->renderedWidgets as $widget) {
            if ($widget->field->actions !== [] || $widget->actions !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<Element>
     */
    private function pageAnchor(?int $page): array
    {
        if ($page === null || $page < 1 || isset($this->pages[$page])) {
            return [];
        }
        $this->pages[$page] = true;
        return [new Element('span', ['id' => 'PDF-Page-' . $page, 'role' => 'doc-pagebreak', 'aria-label' => $this->document->pageLabels[$page] ?? (string) $page])];
    }

    private function hasFormLabelBlocks(StructureElement $source): bool
    {
        foreach ($source->children as $child) {
            if ($child instanceof StructureElement && in_array($this->roles->resolve($child)->type, ['Form', 'Figure', 'Formula', 'Caption'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{float, float, float, float}|null
     */
    private function boundingBox(StructureElement $source): ?array
    {
        $attributes = $source->attributes;
        foreach ($source->properties->classes as $name) {
            $attributes = [...($this->document->classMap[$name] ?? []), ...$attributes];
        }
        $result = null;
        foreach ($attributes as $attribute) {
            $box = $attribute->values['BBox'] ?? null;
            if (is_array($box) && count($box) === 4 && array_is_list($box)) {
                $values = [];
                foreach ($box as $number) {
                    if ((!is_float($number) && !is_int($number)) || !is_finite((float) $number)) {
                        throw new DerivationException('An associated SVG bounding box must contain four finite numbers.');
                    }
                    $values[] = (float) $number;
                }
                $result = [$values[0], $values[1], $values[2], $values[3]];
            }
        }
        return $result;
    }

    /**
     * @param list<SourceNode> $children
     * @return list<Element>
     */
    private function replacementPageAnchors(array $children): array
    {
        $output = [];
        foreach ($children as $child) {
            $page = property_exists($child, 'page') ? $child->page : null;
            if (is_int($page)) {
                array_push($output, ...$this->pageAnchor($page));
            }
            if ($child instanceof StructureElement || $child instanceof ContentGroup || $child instanceof MarkedContent) {
                array_push($output, ...$this->replacementPageAnchors($child->children));
            }
        }
        return $output;
    }

    private function validateOutputIdentifiers(HtmlNode $root): void
    {
        $ids = [];
        $anchors = [];
        $tableCells = [];
        $stack = [$root];
        while ($stack !== []) {
            $node = array_pop($stack);
            if (!$node instanceof Element) {
                continue;
            }
            if (isset($node->attributes['id'])) {
                $id = $node->attributes['id'];
                if ($id === '' || preg_match('/\s/u', $id) === 1 || isset($ids[$id])) {
                    throw new DerivationException('The derived HTML contains an empty, duplicate or invalid identifier.');
                }
                $ids[$id] = true;
            }
            if ($node->tag === 'a') {
                $anchors[] = $node;
            }
            if (isset($this->tableHeaderTargets[spl_object_id($node)])) {
                $tableCells[] = $node;
            }
            array_push($stack, ...$node->children);
        }
        foreach ($tableCells as $cell) {
            $headers = [];
            foreach ($this->tableHeaderTargets[spl_object_id($cell)][1] as $target) {
                $target = $this->destinationAliases[$target] ?? $target;
                if (isset($ids[$target])) {
                    $headers[] = $target;
                } else {
                    $this->diagnostics[] = new Diagnostic('unresolved-table-header', 'A table-header target whose content was not derived has no output identifier.', $target);
                }
            }
            $cell->attributes['headers'] = implode(' ', array_unique($headers));
        }
        foreach ($anchors as $anchor) {
            // References are resolved after alternatives and content-model repair.
            $href = $anchor->attributes['href'] ?? '';
            $destination = str_starts_with($href, '#') ? rawurldecode(substr($href, 1)) : null;
            if ($destination !== null && isset($this->destinationAliases[$destination])) {
                $href = '#' . rawurlencode($this->destinationAliases[$destination]);
                $anchor->attributes['href'] = $href;
            }
            if (str_starts_with($href, '#') && !isset($ids[rawurldecode(substr($href, 1))])) {
                unset($anchor->attributes['href']);
                $this->diagnostics[] = new Diagnostic('unresolved-output-destination', 'A destination whose content was not derived has no output anchor.', $href);
            }
        }
    }
}
