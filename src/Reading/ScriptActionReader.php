<?php

declare(strict_types=1);

namespace PDF\Html\Reading;

use PDF\Reading\EncodedStream;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\StringType;

/** Read ordered JavaScript action chains without executing source code. */
final readonly class ScriptActionReader
{
    public function __construct(private SourceValues $values)
    {
    }

    public function read(?BasicType $source): ?string
    {
        $dictionary = $this->values->dictionary($source);
        if ($dictionary === null) {
            return null;
        }
        $seen = [];
        $scripts = $this->actions($dictionary, 1, $seen);
        return $scripts === [] ? null : implode("\n;\n", $scripts);
    }

    /**
     * @param array<int, true> $seen
     * @return list<string>
     */
    private function actions(BasicType $source, int $depth, array &$seen): array
    {
        $this->values->budget->depth($depth);
        $this->values->budget->consume('nodes');
        $action = $this->values->document->resolve($source);
        if ($action instanceof NullType) {
            return [];
        }
        if (!$action instanceof DictionaryType && !$action instanceof ArrayType) {
            throw new ReadingException('A script action chain requires dictionaries or ordered arrays.');
        }
        $identity = spl_object_id($action);
        if (isset($seen[$identity])) {
            throw new ReadingException('A script action chain contains a cycle.');
        }
        $seen[$identity] = true;
        try {
            $scripts = [];
            if ($action instanceof ArrayType) {
                foreach ($action as $child) {
                    array_push($scripts, ...$this->actions($child, $depth + 1, $seen));
                }
                return $scripts;
            }
            if ($this->values->name($action->get('S')) === 'JavaScript') {
                $script = $this->values->value($action, 'JS');
                if ($script instanceof EncodedStream) {
                    $script = new LiteralString($this->values->document->streamData($script));
                }
                if (!$script instanceof StringType) {
                    throw new ReadingException('A JavaScript action requires a text string or stream.');
                }
                $text = $this->values->text($script);
                if ($text !== null) {
                    $scripts[] = $text;
                }
            } else {
                $this->values->budget->diagnostic('A non-JavaScript action in an ECMAScript chain requires an application action handler.');
            }
            if ($action->get('Next') !== null) {
                array_push($scripts, ...$this->actions($action->get('Next'), $depth + 1, $seen));
            }
            return $scripts;
        } finally {
            unset($seen[$identity]);
        }
    }
}
