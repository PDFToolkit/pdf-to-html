"""Independent HTML5 oracle for page content parents and expanded form fields."""
import json
import sys

import html5lib


cases = json.load(open(sys.argv[1], encoding="utf-8-sig"))
html_ns = "{http://www.w3.org/1999/xhtml}"
for name, case in cases.items():
    parser = html5lib.HTMLParser(tree=html5lib.getTreeBuilder("etree"))
    tree = parser.parse(case["html"])
    assert not parser.errors, (name, parser.errors)
    identifiers = [node.get("id") for node in tree.iter() if node.get("id")]
    assert len(identifiers) == len(set(identifiers)), (name, identifiers)
    by_id = {node.get("id"): node for node in tree.iter() if node.get("id")}
    for anchor in tree.iter(html_ns + "a"):
        assert anchor.get("href", "").removeprefix("#") in by_id, name
    for identifier, node in by_id.items():
        if not identifier.startswith("PDF-Page-"):
            continue
        if name == "omitted":
            assert node.get("role") == "doc-pagebreak" and not list(node)
            assert case["diagnostics"]
        else:
            assert node.get("role") != "doc-pagebreak", name
            assert any(child.get("role") == "doc-pagebreak" and child.get("id") is None for child in node), name
            assert "".join(node.itertext()) or any(child.tag in {html_ns + "img", html_ns + "input", html_ns + "abbr", html_ns + "table"} or child.tag.endswith("}math") for child in list(node)[1:]), name
    if name == "widget-expansion":
        field = by_id["person"]
        parent = next(node for node in tree.iter() if field in list(node))
        assert parent.tag == html_ns + "abbr" and parent.get("title") == "Identité complète"
        assert any(label.get("for") == "person" for label in tree.iter(html_ns + "label"))
print(f"PASS {len(cases)} HTML5 page identifier scenarios, unique targets, navigation and field expansion")
