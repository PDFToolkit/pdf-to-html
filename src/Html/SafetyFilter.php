<?php

declare(strict_types=1);

namespace PDF\Html\Html;

use PDF\Html\DerivationException;
use PDF\Html\DerivationLimits;
use PDF\Html\DerivationPolicy;
use PDF\Html\Diagnostic;

/** Security profile permitted by 4.6.1, separate from semantic derivation. */
final class SafetyFilter
{
    private const HTML = 'http://www.w3.org/1999/xhtml';
    private const MATHML = 'http://www.w3.org/1998/Math/MathML';
    // Vocabulary names verified against the W3C MathML 3.0 Second Edition RelaxNG modules.
    // https://www.w3.org/Math/RelaxNG/mathml3/ — names only, not a copied schema implementation.
    private const MATHML_ELEMENTS = [
        'abs', 'and', 'annotation', 'annotation-xml', 'apply', 'approx',
        'arccos', 'arccosh', 'arccot', 'arccoth', 'arccsc', 'arccsch',
        'arcsec', 'arcsech', 'arcsin', 'arcsinh', 'arctan', 'arctanh',
        'arg', 'bind', 'bvar', 'card', 'cartesianproduct', 'cbytes',
        'ceiling', 'cerror', 'ci', 'cn', 'codomain', 'complexes',
        'compose', 'condition', 'conjugate', 'cos', 'cosh', 'cot',
        'coth', 'cs', 'csc', 'csch', 'csymbol', 'curl',
        'declare', 'degree', 'determinant', 'diff', 'divergence', 'divide',
        'domain', 'domainofapplication', 'emptyset', 'eq', 'equivalent', 'eulergamma',
        'exists', 'exp', 'exponentiale', 'factorial', 'factorof', 'false',
        'floor', 'fn', 'forall', 'gcd', 'geq', 'grad',
        'gt', 'ident', 'image', 'imaginary', 'imaginaryi', 'implies',
        'in', 'infinity', 'int', 'integers', 'intersect', 'interval',
        'inverse', 'lambda', 'laplacian', 'lcm', 'leq', 'limit',
        'ln', 'log', 'logbase', 'lowlimit', 'lt', 'maction',
        'maligngroup', 'malignmark', 'math', 'matrix', 'matrixrow', 'max',
        'mean', 'median', 'menclose', 'merror', 'mfenced', 'mfrac',
        'mglyph', 'mi', 'min', 'minus', 'mlabeledtr', 'mlongdiv',
        'mmultiscripts', 'mn', 'mo', 'mode', 'moment', 'momentabout',
        'mover', 'mpadded', 'mphantom', 'mprescripts', 'mroot', 'mrow',
        'ms', 'mscarries', 'mscarry', 'msgroup', 'msline', 'mspace',
        'msqrt', 'msrow', 'mstack', 'mstyle', 'msub', 'msubsup',
        'msup', 'mtable', 'mtd', 'mtext', 'mtr', 'munder',
        'munderover', 'naturalnumbers', 'neq', 'none', 'not', 'notanumber',
        'notin', 'notprsubset', 'notsubset', 'or', 'otherwise', 'outerproduct',
        'partialdiff', 'pi', 'piece', 'piecewise', 'plus', 'power',
        'primes', 'product', 'prsubset', 'quotient', 'rationals', 'real',
        'reals', 'reln', 'rem', 'root', 'scalarproduct', 'sdev',
        'sec', 'sech', 'selector', 'semantics', 'sep', 'set',
        'setdiff', 'share', 'sin', 'sinh', 'subset', 'sum',
        'tan', 'tanh', 'tendsto', 'times', 'transpose', 'true',
        'union', 'uplimit', 'variance', 'vector', 'vectorproduct', 'xor',
    ];
    private const MATHML_ATTRIBUTES = [
        'accent', 'accentunder', 'actiontype', 'align', 'alignmentscope', 'alt',
        'altimg', 'altimg-height', 'altimg-valign', 'altimg-width', 'alttext', 'arg',
        'background', 'base', 'bevelled', 'cd', 'cdgroup', 'charalign',
        'charspacing', 'class', 'close', 'closure', 'color', 'columnalign',
        'columnlines', 'columnspacing', 'columnspan', 'columnwidth', 'crossout', 'decimalpoint',
        'definitionurl', 'denomalign', 'depth', 'dir', 'display', 'displaystyle',
        'edge', 'encoding', 'equalcolumns', 'equalrows', 'fence', 'fontfamily',
        'fontsize', 'fontstyle', 'fontweight', 'form', 'frame', 'framespacing',
        'groupalign', 'height', 'href', 'id', 'indentalign', 'indentalignfirst',
        'indentalignlast', 'indentshift', 'indentshiftfirst', 'indentshiftlast', 'indenttarget', 'index',
        'infixlinebreakstyle', 'intent', 'largeop', 'leftoverhang', 'length', 'linebreak',
        'linebreakmultchar', 'linebreakstyle', 'lineleading', 'linethickness', 'location', 'longdivstyle',
        'lquote', 'lspace', 'macros', 'mathbackground', 'mathcolor', 'mathsize',
        'mathvariant', 'maxsize', 'maxwidth', 'mediummathspace', 'minlabelspacing', 'minsize',
        'mode', 'movablelimits', 'mslinethickness', 'name', 'nargs', 'notation',
        'numalign', 'occurrence', 'open', 'order', 'other', 'overflow',
        'position', 'rightoverhang', 'rowalign', 'rowlines', 'rowspacing', 'rowspan',
        'rquote', 'rspace', 'scope', 'scriptlevel', 'scriptminsize', 'scriptsizemultiplier',
        'selection', 'separator', 'separators', 'shift', 'side', 'src',
        'stackalign', 'stretchy', 'style', 'subscriptshift', 'superscriptshift', 'symmetric',
        'thickmathspace', 'thinmathspace', 'type', 'valign', 'verythickmathspace', 'verythinmathspace',
        'veryverythickmathspace', 'veryverythinmathspace', 'voffset', 'width', 'xlink:href', 'xml:lang',
        'xml:space', 'xref',
    ];
    private const MATHML_CORE = [
        'annotation', 'annotation-xml', 'maction', 'math', 'merror', 'mfrac',
        'mi', 'mmultiscripts', 'mn', 'mo', 'mover', 'mpadded',
        'mphantom', 'mprescripts', 'mroot', 'mrow', 'ms', 'mspace',
        'msqrt', 'mstyle', 'msub', 'msubsup', 'msup', 'mtable',
        'mtd', 'mtext', 'mtr', 'munder', 'munderover', 'semantics',
    ];
    private const HTML_ELEMENTS = [
        'a', 'abbr', 'article', 'aside', 'b', 'bdi',
        'bdo', 'blockquote', 'br', 'button', 'caption', 'cite',
        'code', 'col', 'colgroup', 'data', 'datalist', 'dd',
        'del', 'dfn', 'div', 'dl', 'dt', 'em',
        'figcaption', 'figure', 'footer', 'form', 'h1', 'h2',
        'h3', 'h4', 'h5', 'h6', 'header', 'hr',
        'i', 'img', 'input', 'ins', 'kbd', 'label',
        'li', 'main', 'mark', 'nav', 'ol', 'option',
        'optgroup', 'p', 'pre', 'q', 'rb', 'rtc',
        'rp', 'rt', 'ruby', 's', 'samp', 'section',
        'select', 'small', 'span', 'strong', 'sub', 'sup',
        'table', 'tbody', 'td', 'textarea', 'tfoot', 'th',
        'thead', 'time', 'tr', 'u', 'ul', 'var',
        'wbr', 'math',
    ];
    private bool $reportedExtendedMathML = false;

    /** @var list<Diagnostic> */
    public array $diagnostics = [];
    private int $nodes = 0;
    private int $fragmentBytes = 0;
    private int $fragmentTokens = 0;

    public function __construct(private readonly DerivationPolicy $policy, private readonly DerivationLimits $limits)
    {
    }

    public function url(string $url, bool $resource = false): ?string
    {
        // Browser URL parsing ignores some ASCII whitespace: reject it before parsing.
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }
        if (str_starts_with($url, '#')) {
            return $url;
        }
        if (str_starts_with(strtolower($url), 'data:')) {
            return $resource && preg_match('~\Adata:image/(?:png|jpeg|gif);base64,[A-Za-z0-9+/]*={0,2}\z~D', $url) === 1 ? $url : null;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (is_string($scheme)) {
            $allowed = $resource ? ['https', 'http'] : ['https', 'http', 'mailto', 'tel'];
            return in_array(strtolower($scheme), $allowed, true) && (!$resource || $this->policy->allowRemoteResources) ? $url : null;
        }
        if (str_starts_with($url, '//') || str_starts_with($url, '/') || str_contains(rawurldecode($url), '..') || str_contains($url, ':')) {
            return null;
        }
        return $resource ? (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/D', $url) === 1 ? $url : null) : $url;
    }

    /** @param array<string, string> $declarations
     * @return array<string, string> */
    public function css(array $declarations): array
    {
        $result = [];
        foreach ($declarations as $property => $value) {
            if (preg_match('/\A(?:--)?[a-zA-Z][a-zA-Z0-9-]*\z/D', $property) !== 1
                || preg_match('/[{};<>\\\\\x00-\x1f]|@|url\s*\(|expression\s*\(|behavior|binding|\/\*/i', $value) === 1) {
                $this->diagnostics[] = new Diagnostic('blocked-css', 'A CSS declaration was omitted by the safe output policy.', $property);
                continue;
            }
            $result[$property] = $value;
        }
        return $result;
    }

    /**
     * @return list<HtmlNode>
     */
    public function fragment(string $html, bool $math = false, bool $head = false): array
    {
        if ($math && $head) {
            throw new DerivationException('A MathML fragment cannot be parsed as head metadata.');
        }
        $bytes = strlen($html);
        if ($bytes > $this->limits->maximumOutputBytes - $this->fragmentBytes
            || preg_match('/<!\\s*(?:DOCTYPE|ENTITY)/i', $html) === 1) {
            throw new DerivationException('Associated fragments exceed their byte budget or contain document/entity declarations.');
        }
        // Count without materializing token arrays before asking libxml to allocate a DOM.
        $tokens = preg_match_all('/[<&]/', $html);
        if ($tokens === false || $tokens > $this->limits->maximumNodes * 8 - $this->fragmentTokens) {
            throw new DerivationException('Associated fragments exceed their cumulative preparse token budget.');
        }
        $this->fragmentBytes += $bytes;
        $this->fragmentTokens += $tokens;
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            if ($math) {
                $ok = $dom->loadXML('<fragment>'.$html.'</fragment>', LIBXML_NONET);
                $parent = $dom->documentElement;
            } else {
                $wrapper = $head ? '<head>'.$html.'</head><body></body>' : '<body>'.$html.'</body>';
                $ok = $dom->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html>'.$wrapper.'</html>', LIBXML_NONET | LIBXML_COMPACT);
                $parent = $dom->getElementsByTagName($head ? 'head' : 'body')->item(0);
            }
            if (!$ok || !$parent instanceof \DOMElement) {
                throw new DerivationException('An associated HTML or MathML fragment could not be parsed.');
            }
            $nodes = $this->domChildren($parent, 0, $head, $math ? self::MATHML : self::HTML, $math);
            foreach ($nodes as $node) {
                if ($node instanceof Element) {
                    $this->filter($node, 0, $math || $node->tag === 'math' ? self::MATHML : self::HTML);
                }
            }
            return $nodes;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function filter(Element $element, int $depth = 0, ?string $namespace = null): void
    {
        if (++$this->nodes > $this->limits->maximumNodes * 8 || $depth > $this->limits->maximumDepth * 2) {
            throw new DerivationException('The HTML output tree limit was exceeded.');
        }
        $element->tag = strtolower($element->tag);
        $namespace ??= $element->tag === 'math' ? self::MATHML : self::HTML;
        if ($namespace === self::MATHML && !in_array($element->tag, self::MATHML_ELEMENTS, true)) {
            throw new DerivationException('An HTML or unknown element cannot masquerade as MathML.');
        }
        if ($namespace === self::MATHML && !in_array($element->tag, self::MATHML_CORE, true) && !$this->reportedExtendedMathML) {
            $this->reportedExtendedMathML = true;
            $this->diagnostics[] = new Diagnostic('extended-mathml-vocabulary', 'Full MathML 3 content or legacy presentation vocabulary is retained; native browser MathML Core rendering is not guaranteed.');
        }
        $attrs = [];
        foreach ($element->attributes as $name => $value) {
            if (strlen($name) + strlen($value) > $this->limits->maximumOutputBytes) {
                throw new DerivationException('An HTML attribute exceeds the output byte limit.');
            }
            $name = strtolower($name);
            if (!$this->attributeAllowed($element->tag, $name, $namespace)) {
                $this->diagnostics[] = new Diagnostic('blocked-attribute', 'An unsupported or active HTML attribute was omitted.', $name);
                continue;
            }
            if (in_array($name, ['href', 'src', 'action', 'formaction', 'poster', 'cite','xlink:href','definitionurl','altimg','macros','cdgroup'], true)) {
                $value = $this->url($value, in_array($name, ['src', 'poster','altimg','macros','cdgroup'], true) || $element->tag === 'link') ?? '';
                if ($value === '') {
                    $this->diagnostics[] = new Diagnostic('blocked-url', 'A URL was omitted by the output policy.', $name);
                    continue;
                }
            }
            if ($name === 'xmlns' && ($element->tag !== 'math' || $value !== self::MATHML)) {
                $this->diagnostics[] = new Diagnostic('blocked-namespace-declaration', 'An incompatible namespace declaration was omitted.');
                continue;
            }
            if ($namespace === self::MATHML && in_array($name, ['mathbackground','mathcolor','mathsize','color','background','fontsize','fontfamily','scriptminsize','scriptsizemultiplier'], true) && $this->css(['math-'.$name => $value]) === []) {
                continue;
            }
            if ($name === 'style') {
                $declarations = [];
                foreach (explode(';', $value) as $declaration) {
                    $parts = explode(':', $declaration, 2);
                    if (count($parts) === 2) {
                        $declarations[trim($parts[0])] = trim($parts[1]);
                    }
                }
                $value = $this->cssText($this->css($declarations));
                if ($value === '') {
                    continue;
                }
            }
            $attrs[$name] = $value;
        }
        $element->attributes = $attrs;
        if ($element->tag === 'style') {
            $text = '';
            foreach ($element->children as $child) {
                if (!$child instanceof Text || strlen($child->value) > $this->limits->maximumOutputBytes - strlen($text)) {
                    throw new DerivationException('A style element contains unsupported or oversized content.');
                }
                $text .= $child->value;
            }
            $element->children = [new Text($this->stylesheet($text))];
        }
        $children = [];
        foreach ($element->children as $child) {
            if ($child instanceof Element) {
                $childNamespace = $this->childNamespace($namespace, $element->tag, $element->attributes, strtolower($child->tag));
                $headMetadata = $element->tag === 'head' && in_array($child->tag, ['meta','title','link','style','script'], true);
                if (($headMetadata && !$this->metadataAllowed($child, true)) || (!$headMetadata && !$this->tagAllowed(strtolower($child->tag), $childNamespace))) {
                    $this->diagnostics[] = new Diagnostic('blocked-output-element', 'An unsafe or unsupported output element was omitted.', $child->tag);
                    continue;
                }
                $this->filter($child, $depth + 1, $childNamespace);
            }
            $children[] = $child;
        }
        $element->children = $children;
    }

    /** @param array<string, string> $declarations */
    public function cssText(array $declarations): string
    {
        $parts = [];
        foreach ($declarations as $name => $value) {
            $parts[] = $name . ':' . $value;
        }
        return implode(';', $parts);
    }

    public function tagAllowed(string $tag, ?string $namespace = null): bool
    {
        if ($namespace !== null && !in_array($namespace, [self::HTML, self::MATHML], true)) {
            return false;
        }
        if ($namespace === self::MATHML) {
            return in_array($tag, self::MATHML_ELEMENTS, true);
        }
        if ($namespace === null && in_array($tag, self::MATHML_ELEMENTS, true)) {
            return true;
        }
        if ($this->policy->allowActiveContent && in_array($tag, ['script','style','link'], true)) {
            return true;
        }
        return in_array($tag, self::HTML_ELEMENTS, true);
    }

    /** The HTML parser recognizes these namespace integration points, not arbitrary xmlns declarations. */
    /** @param array<string,string> $attributes */
    private function childNamespace(string $parentNamespace, string $parentTag, array $attributes, string $childTag): string
    {
        if ($childTag === 'math') {
            return self::MATHML;
        }
        if ($parentNamespace !== self::MATHML) {
            return self::HTML;
        }
        if ($parentTag === 'annotation-xml' && in_array(strtolower($attributes['encoding'] ?? ''), ['text/html','application/xhtml+xml'], true)) {
            return self::HTML;
        }
        if (in_array($parentTag, ['mi','mo','mn','ms','mtext'], true) && !in_array($childTag, ['mglyph','malignmark'], true)) {
            return self::HTML;
        }
        return self::MATHML;
    }

    /**
     * @return list<HtmlNode>
     */
    private function domChildren(\DOMNode $parent, int $depth, bool $head = false, string $namespace = self::HTML, bool $xml = false): array
    {
        if ($depth > $this->limits->maximumDepth) {
            throw new DerivationException('An associated fragment exceeds the nesting limit.');
        }
        $parentTag = strtolower($parent->localName ?? $parent->nodeName);
        $parentAttributes = [];
        if ($parent instanceof \DOMElement && $parent->hasAttribute('encoding')) {
            $parentAttributes['encoding'] = $parent->getAttribute('encoding');
        }
        $result = [];
        foreach ($parent->childNodes as $child) {
            if (++$this->nodes > $this->limits->maximumNodes * 8) {
                throw new DerivationException('An associated fragment exceeds the node limit.');
            }
            if ($child instanceof \DOMText) {
                if (!$head) {
                    $result[] = new Text($child->data);
                } elseif (trim($child->data) !== '') {
                    $this->diagnostics[] = new Diagnostic('blocked-head-text', 'Non-metadata text in a head fragment was omitted.');
                }
            } elseif ($child instanceof \DOMElement) {
                $tag = strtolower($child->localName ?? $child->tagName);
                $targetNamespace = $this->childNamespace($namespace, $parentTag, $parentAttributes, $tag);
                $actualNamespace = $child->namespaceURI;
                if (($xml && $targetNamespace === self::HTML && $actualNamespace !== self::HTML)
                    || ($actualNamespace !== null && $actualNamespace !== '' && $actualNamespace !== $targetNamespace)) {
                    $this->diagnostics[] = new Diagnostic('blocked-fragment-namespace', 'A foreign or incompatible XML namespace was omitted.', $actualNamespace);
                    continue;
                }
                // XML namespace declarations cannot turn HTML active names into MathML elements.
                if ($xml && $actualNamespace === self::MATHML && !in_array($tag, self::MATHML_ELEMENTS, true)) {
                    $this->diagnostics[] = new Diagnostic('blocked-mathml-name', 'An unrecognized MathML element name was omitted.', $tag);
                    continue;
                }
                $metadata = in_array($tag, ['meta','title','link','style','script'], true);
                if (($head && !$metadata) || (!$head && !$this->tagAllowed($tag, $targetNamespace))) {
                    $this->diagnostics[] = new Diagnostic('blocked-fragment-element', 'An unsafe or unsupported associated fragment element was omitted.', $tag);
                    continue;
                }
                $attrs = [];
                foreach ($child->attributes as $attribute) {
                    $attrs[strtolower($attribute->name)] = $attribute->value;
                }
                $node = new Element($tag, $attrs);
                if ($head && !$this->metadataAllowed($node)) {
                    continue;
                }
                $node->children = $this->domChildren($child, $depth + 1, false, $targetNamespace, $xml);
                $result[] = $node;
            }
        }
        return $result;
    }

    private function metadataAllowed(Element $element, bool $derivedHead = false): bool
    {
        $tag = $element->tag;
        if ($tag === 'meta') {
            if (isset($element->attributes['http-equiv'])) {
                $this->diagnostics[] = new Diagnostic('blocked-meta-directive', 'HTTP-equivalent metadata, including refresh, is never imported.');
                return false;
            }
            if (isset($element->attributes['charset'])) {
                if (strcasecmp($element->attributes['charset'], 'UTF-8') !== 0) {
                    $this->diagnostics[] = new Diagnostic('blocked-meta-encoding', 'Only the UTF-8 character encoding is retained.');
                    return false;
                }
                $element->attributes = ['charset' => 'UTF-8'];
                return true;
            }
            return isset($element->attributes['name'], $element->attributes['content']);
        }
        if ($tag === 'title') {
            return true;
        }
        if ($tag === 'link') {
            $relations = preg_split('/\\s+/', strtolower(trim($element->attributes['rel'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $allowed = ['stylesheet','icon','alternate','author','help','license','next','prev','search'];
            if ($this->policy->allowActiveContent) {
                $allowed[] = 'import';
            }
            if ($relations === false || $relations === [] || array_diff($relations, $allowed) !== []
                || $this->url($element->attributes['href'] ?? '', true) === null) {
                $this->diagnostics[] = new Diagnostic('blocked-head-resource', 'A head link was omitted by the resource policy.');
                return false;
            }
            return isset($element->attributes['href']);
        }
        if ($tag === 'style' && $derivedHead) {
            // Generated CSS has already passed the derivation policy; filter() sanitizes the stylesheet again.
            return true;
        }
        if (in_array($tag, ['style','script'], true)) {
            if (!$this->policy->allowActiveContent) {
                $this->diagnostics[] = new Diagnostic('blocked-head-active', 'Active head content requires the explicit trusted policy.', $tag);
                return false;
            }
            return true;
        }
        return false;
    }

    /** Restricted stylesheet syntax: no imports, URLs, escapes or nested rules. */
    public function stylesheet(string $sheet): string
    {
        if (strlen($sheet) > $this->limits->maximumOutputBytes) {
            throw new DerivationException('A stylesheet exceeds the output byte limit.');
        }
        $withoutComments = '';
        $position = 0;
        while (($start = strpos($sheet, '/*', $position)) !== false) {
            $end = strpos($sheet, '*/', $start + 2);
            if ($end === false) {
                $this->diagnostics[] = new Diagnostic('blocked-stylesheet-comment', 'An unterminated CSS comment was omitted with its stylesheet.');
                return '';
            }
            $withoutComments .= substr($sheet, $position, $start - $position);
            $position = $end + 2;
        }
        $sheet = $withoutComments.substr($sheet, $position);
        $result = '';
        $offset = 0;
        while ($offset < strlen($sheet)) {
            if (preg_match('/\\G\\s*\\z/D', $sheet, $empty, 0, $offset) === 1) {
                break;
            }
            if (preg_match('/\\G\\s*([^{}]+)\\{([^{}]*)\\}\\s*/s', $sheet, $match, 0, $offset) !== 1) {
                $this->diagnostics[] = new Diagnostic('blocked-stylesheet-syntax', 'Unsupported or nested stylesheet syntax was omitted.');
                break;
            }
            $offset += strlen($match[0]);
            $selector = trim($match[1]);
            if (preg_match('/\\A[a-zA-Z0-9_.*#,:>+~\\s\\[\\]="\'|^$()-]+\\z/D', $selector) !== 1) {
                $this->diagnostics[] = new Diagnostic('blocked-stylesheet-selector', 'An unsupported stylesheet selector was omitted.');
                continue;
            }
            $declarations = [];
            foreach (explode(';', $match[2]) as $part) {
                $pair = explode(':', $part, 2);
                if (count($pair) === 2) {
                    $declarations[trim($pair[0])] = trim($pair[1]);
                }
            }
            $css = $this->cssText($this->css($declarations));
            if ($css !== '') {
                $result .= $selector.'{'.$css.'}';
            }
        }
        return $result;
    }

    private function attributeAllowed(string $tag, string $name, ?string $namespace = null): bool
    {
        if (str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'is', 'xmlns:xlink'], true)) {
            return $this->policy->allowActiveContent && str_starts_with($name, 'on');
        }
        if (preg_match('/\A(?:data|aria)-[a-z0-9_.:-]+\z/D', $name) === 1
            || in_array($name, ['id', 'class', 'lang', 'title', 'style', 'role', 'dir', 'hidden', 'tabindex', 'translate'], true)) {
            return true;
        }
        if ($namespace === self::MATHML) {
            return ($name === 'xmlns' && $tag === 'math')
                || ($name === 'autofocus' && $this->policy->allowActiveContent)
                || in_array($name, self::MATHML_ATTRIBUTES, true);
        }
        $specific = [
            'a' => ['href', 'target', 'rel', 'download', 'type', 'hreflang'],
            'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
            'form' => ['name', 'action', 'method', 'enctype', 'novalidate', 'autocomplete'],
            'label' => ['for'], 'ol' => ['start', 'reversed', 'type'], 'li' => ['value'],
            'input' => ['type', 'name', 'value', 'checked', 'disabled', 'readonly', 'required', 'maxlength', 'minlength', 'spellcheck', 'form', 'list', 'multiple', 'accept', 'min', 'max', 'step', 'placeholder', 'pattern', 'autocomplete', 'size', 'formaction', 'formmethod'],
            'textarea' => ['name', 'disabled', 'readonly', 'required', 'maxlength', 'minlength', 'spellcheck', 'form', 'rows', 'cols', 'placeholder', 'wrap'],
            'select' => ['name', 'disabled', 'required', 'form', 'multiple', 'size', 'autocomplete'],
            'option' => ['value', 'selected', 'disabled', 'label'], 'optgroup' => ['label', 'disabled'],
            'button' => ['type', 'name', 'value', 'form', 'disabled', 'formaction', 'formmethod'],
            'td' => ['colspan', 'rowspan', 'headers'], 'th' => ['colspan', 'rowspan', 'headers', 'scope', 'abbr'],
            'col' => ['span'], 'colgroup' => ['span'], 'time' => ['datetime'], 'data' => ['value'],
            'meta' => ['name','content','charset'], 'title' => [],
            'script' => ['src', 'type', 'defer'], 'style' => ['type', 'media'], 'link' => ['href', 'rel', 'type', 'media'],
            'math' => ['xmlns', 'display', 'alttext'],
        ];
        if (in_array($name, $specific[$tag] ?? [], true)) {
            return true;
        }
        return false;
    }
}
