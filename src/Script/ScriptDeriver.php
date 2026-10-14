<?php

declare(strict_types=1);

namespace PDF\Html\Script;

use PDF\Html\Model\TaggedDocument;
use PDF\Html\Model\Widget;
use PDF\Html\DerivationException;

/**
 * Trusted, opt-in document-scoped Annex B bridge.
 * Field/page actions close over one shared document-script module in explicitly requested output.
 * No PHP-side eval, subprocess, network or filesystem access is performed.
 */
final class ScriptDeriver
{
    /**
     * @var list<string>
     */
    public array $diagnostics = [];
    /**
     * @param list<Widget> $widgets
     * @param list<WidgetBinding>|null $bindings Null retains the manual API's positional widget matching.
     */
    public function derive(TaggedDocument $document, array $widgets, ?array $bindings = null): ?string
    {
        $this->diagnostics = [];
        if ($bindings !== null && count($bindings) !== count($widgets)) {
            throw new DerivationException('Explicit script bindings must identify every derived widget.');
        }
        $fields = [];
        $widgetActions = [];
        $boundWidgets = $bindings === null ? null : [];
        $boundIds = [];
        $functions = [];
        foreach ($widgets as $index => $widget) {
            $field = $widget->field;
            if (!isset($fields[$field->name])) {
                $actions = [];
                foreach ($field->actions as $event => $source) {
                    if (!in_array($event, ['K', 'V', 'C', 'F'], true)) {
                        continue;
                    }
                    $key = 'f'.count($functions);
                    $functions[$key] = $source;
                    $actions[$event] = $key;
                }
                $fields[$field->name] = ['value' => $field->value, 'actions' => $actions, 'readonly' => ($field->flags & 1) !== 0];
            }
            $actions = [];
            // Older source models mixed field and widget actions in FormField.
            // Keep that input compatible without sharing annotation-specific events.
            foreach ([...$field->actions, ...$widget->actions] as $event => $source) {
                if (!in_array($event, ['Fo', 'Bl', 'E', 'X', 'D', 'U'], true)) {
                    continue;
                }
                $key = 'f'.count($functions);
                $functions[$key] = $source;
                $actions[$event] = $key;
            }
            $widgetActions[$field->name][] = (object) $actions;
            if ($bindings !== null) {
                $binding = $bindings[$index];
                if ($binding->widget !== $widget || isset($boundIds[$binding->htmlId])) {
                    throw new DerivationException('Explicit script bindings must match their widget and use unique HTML identifiers.');
                }
                $boundIds[$binding->htmlId] = true;
                $boundWidgets[] = ['id' => $binding->htmlId, 'name' => $field->name, 'actions' => (object) $actions];
            }
        }
        $pages = [];
        foreach ($document->pageScripts as $page => $actions) {
            foreach ($actions as $event => $source) {
                $key = 'f'.count($functions);
                $functions[$key] = $source;
                $pages[(string) $page][$event] = $key;
            }
        }
        if ($functions === [] && $widgets === [] && $document->documentScripts === []) {
            return null;
        }
        $this->diagnostics[] = 'Opt-in PDF JavaScript bridge supports common field/event/calculation operations, not the complete ISO 21757-1 API.';
        $this->diagnostics[] = 'Browser permission enforcement and document trust remain the host application responsibility.';
        $this->diagnostics[] = 'Document scripts share a strict lexical document module; arbitrary host globals and the full Acrobat API are not emulated.';
        $encode = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        $config = $encode(['fields' => (object) $fields, 'widgets' => (object) $widgetActions, 'bindings' => $boundWidgets, 'pages' => (object) $pages]);
        $bodies = [];
        foreach ($functions as $key => $source) {
            $bodies[] = $encode($key).":function(event){\n".$source."\n}";
        }
        // Deliberately inline trusted bodies: no eval/new Function string compiler is needed.
        $bodyMap = '{'.implode(',', $bodies).'}';
        $runtime = <<<'JS'
(function () {
"use strict";
const config = JSON.parse(__CONFIG__), methods = Object.create(null);
const fieldMap = new Map(), view = document.defaultView;
const rootForm = document.querySelector('form[name="acroform"]');
const widgetBindings = config.bindings === null ? null : new Map(config.bindings.map(binding => [binding.id, binding]));
const controls = () => widgetBindings === null
 ? Array.from(rootForm?.elements ?? []).filter(el => el.name && Object.prototype.hasOwnProperty.call(config.fields, el.name))
 : Array.from(widgetBindings.keys(), id => document.getElementById(id)).filter(el => el && ["INPUT", "SELECT", "TEXTAREA", "BUTTON"].includes(el.tagName));
const sourceFieldName = el => widgetBindings === null ? el.name : widgetBindings.get(el.id)?.name;
const errors = [];
let calculating = false;
const app = Object.freeze({
 viewerVersion: 1, viewerType: "Derivation",
 get activeDocs() { return [pdf]; },
 response: () => null, beep: () => {},
 alert: value => view.alert(typeof value === "object" && value !== null ? String(value.cMsg ?? "") : String(value))
});
const pdf = {
 pageNum: 0, numPages: __NUM_PAGES__, app, event: null, errors,
 getField(name) { return fieldMap.get(String(name)) ?? null; },
 calculateNow() { calculate(); },
 resetForm(names) {
  const selected = names == null ? Array.from(fieldMap.keys()) : (Array.isArray(names) ? names : [names]);
  selected.forEach(name => { const field = fieldMap.get(name); if (field) field.value = field.defaultValue; });
  calculate();
 }
};
function report(error) { errors.push(error instanceof Error ? error.message : String(error)); }
function invoke(key, event) {
 if (!key) return;
 const previous = pdf.event; pdf.event = event;
 try { methods[key].call(pdf, event); } catch (error) { event.rc = false; report(error); }
 finally { pdf.event = previous; }
}
function initializeDocumentModule(__pdfDerivationRegisterActions, event) {
 __pdfDerivationRegisterActions(__METHODS__);
 __DOCUMENT_SCRIPTS__
}
class Field {
 constructor(name, definition) {
  this.name = name; this.defaultValue = definition.value; this._value = definition.value;
  this.readonly = definition.readonly; this.actions = definition.actions;
 }
 get value() { return this._value; }
 set value(value) { this._value = value; this.sync(); }
 get valueAsString() { return this._value == null ? "" : Array.isArray(this._value) ? this._value.join(", ") : String(this._value); }
 get widgets() { return controls().filter(el => sourceFieldName(el) === this.name); }
 get display() { return this.widgets.every(el => el.style.display === "none") ? 1 : 0; }
 set display(value) { this.widgets.forEach(el => { el.style.display = Number(value) === 1 ? "none" : ""; }); }
 get required() { return this.widgets.some(el => el.required); }
 set required(value) { this.widgets.forEach(el => { el.required = Boolean(value); }); }
 setFocus() { const el = this.widgets[0]; if (el) el.focus(); }
 getItemAt(index, exported) {
  const el = this.widgets.find(widget => widget.options);
  const item = el?.options[index]; return item ? (exported ? item.value : item.text) : null;
 }
 sync(formatted) {
  const raw = this._value, values = Array.isArray(raw) ? raw.map(String) : [raw == null ? "" : String(raw)];
  this.widgets.forEach(el => {
   if (el.type === "checkbox" || el.type === "radio") el.checked = values.includes(el.value);
   else if (el.tagName === "SELECT") Array.from(el.options).forEach(option => { option.selected = values.includes(option.value); });
   else if (el.type !== "file" && el.tagName !== "BUTTON") el.value = formatted === undefined ? this.valueAsString : String(formatted);
  });
 }
}
function eventFor(field, type, value) {
 return { target: field, source: field, name: type, value, rc: true, change: "", selStart: 0, selEnd: 0, willCommit: false };
}
function format(field) {
 const event = eventFor(field, "Format", field.value);
 invoke(field.actions.F, event); field.sync(event.value);
}
function calculate() {
 if (calculating) return;
 calculating = true;
 try {
  fieldMap.forEach(field => {
   if (!field.actions.C) return;
   const event = eventFor(field, "Calculate", field.value);
   invoke(field.actions.C, event);
   if (event.rc) field.value = event.value;
  });
  fieldMap.forEach(format);
 } finally { calculating = false; }
}
function inputValue(field, element) {
 if (element.type === "radio") return element.value;
 if (element.type === "checkbox") {
  const checked = field.widgets.filter(el => el.type === "checkbox" && el.checked).map(el => el.value);
  return field.widgets.filter(el => el.type === "checkbox").length > 1 ? checked : (checked[0] ?? "Off");
 }
 if (element.tagName === "SELECT" && element.multiple) return Array.from(element.selectedOptions).map(option => option.value);
 return element.value;
}
function install() {
 Object.entries(config.fields).forEach(([name, definition]) => { fieldMap.set(name, new Field(name, definition)); });
 rootForm?.addEventListener("reset", native => { native.preventDefault(); pdf.resetForm(); });
 const widgetOccurrences = new Map();
 controls().forEach(el => {
  const name = sourceFieldName(el), field = fieldMap.get(name);
  const occurrence = widgetOccurrences.get(name) ?? 0;
  widgetOccurrences.set(name, occurrence + 1);
  const actions = widgetBindings === null ? (config.widgets[name]?.[occurrence] ?? {}) : widgetBindings.get(el.id).actions;
  for (const [browser, action, name] of [["focus","Fo","Focus"],["blur","Bl","Blur"],["mouseenter","E","MouseEnter"],["mouseleave","X","MouseExit"],["mousedown","D","MouseDown"],["mouseup","U","MouseUp"]]) {
   el.addEventListener(browser, () => invoke(actions[action], eventFor(field, name, field.value)));
  }
  el.addEventListener("beforeinput", native => {
   if (field.readonly) { native.preventDefault(); return; }
   const event = eventFor(field, "Keystroke", field.value);
   event.change = native.data ?? ""; event.selStart = el.selectionStart ?? 0; event.selEnd = el.selectionEnd ?? 0;
   invoke(field.actions.K, event);
   if (!event.rc) native.preventDefault();
   else if (event.change !== (native.data ?? "") && typeof el.setRangeText === "function") {
    native.preventDefault(); el.setRangeText(String(event.change), event.selStart, event.selEnd, "end");
   }
  });
  el.addEventListener("click", native => { if (field.readonly) { native.preventDefault(); field.sync(); } });
  el.addEventListener("change", () => {
   if (field.readonly) { field.sync(); return; }
   const commit = eventFor(field, "Keystroke", inputValue(field, el)); commit.willCommit = true;
   invoke(field.actions.K, commit);
   if (!commit.rc) { field.sync(); return; }
   const event = eventFor(field, "Validate", commit.value); event.willCommit = true;
   invoke(field.actions.V, event);
   if (event.rc) { field.value = event.value; calculate(); } else field.sync();
  });
 });
 const openEvent = { target: pdf, name: "Open", rc: true };
 const previous = pdf.event; pdf.event = openEvent;
 try { initializeDocumentModule.call(pdf, table => Object.assign(methods, table), openEvent); }
 catch (error) { openEvent.rc = false; report(error); }
 finally { pdf.event = previous; }
 calculate();
 const opened = new Set();
 function pageEvent(number, visible) {
  pdf.pageNum = Math.max(0, Number(number) - 1);
  const actions = config.pages[String(number)] ?? {};
  invoke(actions[visible ? "O" : "C"], { target: pdf, name: visible ? "PageOpen" : "PageClose", rc: true });
 }
 if (typeof view.IntersectionObserver === "function") {
  const observer = new view.IntersectionObserver(entries => entries.forEach(entry => {
   const number = entry.target.id.slice("PDF-Page-".length);
   if (entry.isIntersecting && !opened.has(number)) { opened.add(number); pageEvent(number, true); }
   else if (!entry.isIntersecting && opened.delete(number)) pageEvent(number, false);
  }));
  Object.keys(config.pages).forEach(number => { const anchor = document.getElementById("PDF-Page-" + number); if (anchor) observer.observe(anchor); });
 } else if (Object.keys(config.pages).length) {
  const first = Object.keys(config.pages)[0]; opened.add(first); pageEvent(first, true);
 }
 view.addEventListener("beforeunload", () => opened.forEach(number => pageEvent(number, false)));
}
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", install, { once: true });
else install();
})();
JS;
        $pageNumbers = array_merge(array_keys($document->pageLabels), array_keys($document->pageScripts));
        $pagesCount = $pageNumbers === [] ? 0 : max($pageNumbers);
        return strtr($runtime, ['__CONFIG__' => $encode($config), '__METHODS__' => $bodyMap, '__DOCUMENT_SCRIPTS__' => implode("\n;\n", $document->documentScripts), '__NUM_PAGES__' => (string) $pagesCount]);
    }
}
