"""Cross-check actual PHP derivation with independent PDF and HTML5 parsers."""
from pathlib import Path
import argparse
import hashlib
import json
import math
import re
import subprocess
import tempfile

import html5lib
from pypdf import PdfReader
from pypdf.generic import IndirectObject


def check_html(html: str):
    parser = html5lib.HTMLParser(namespaceHTMLElements=False)
    tree = parser.parse(html)
    assert not parser.errors, parser.errors
    ids = [element.attrib["id"] for element in tree.iter() if "id" in element.attrib]
    assert len(ids) == len(set(ids)), "Duplicate derived IDs"
    for element in tree.iter():
        assert element.tag not in {"script", "iframe", "object", "embed"}
        assert not any(name.lower().startswith("on") for name in element.attrib)
        for name in ("href", "src", "formaction"):
            value = element.attrib.get(name, "")
            assert not value.lower().startswith(("javascript:", "file:", "vbscript:"))
        if element.tag == "a" and element.attrib.get("href", "").startswith("#"):
            assert element.attrib["href"][1:] in ids
    return tree


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--php", default="php")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    fixture = root / "tests/Fixtures/tagged.pdf"
    expected = json.loads((root / "tests/Fixtures/reading-manifest.json").read_text(encoding="utf-8"))
    assert hashlib.sha256(fixture.read_bytes()).hexdigest() == expected["sha256"]
    pdf = PdfReader(fixture)
    assert pdf.pdf_header == expected["header"]
    assert len(pdf.pages) == expected["pages"]
    assert expected["text"] in pdf.pages[0].extract_text()
    assert pdf.trailer["/Root"]["/StructTreeRoot"]["/Type"] == "/StructTreeRoot"
    # A successful text extraction does not prove that the PDF2 font metrics or
    # widget default appearance are valid. Observe these independently as well.
    for reference in pdf.pages[0]["/Resources"]["/Font"].values():
        font = reference.get_object()
        first, last = font["/FirstChar"], font["/LastChar"]
        assert 0 <= first <= last <= 255
        widths = font["/Widths"]
        assert len(widths) == last - first + 1
        assert all(math.isfinite(float(width)) for width in widths)
        assert isinstance(font.raw_get("/FontDescriptor"), IndirectObject)
        descriptor = font["/FontDescriptor"]
        assert descriptor["/FontName"] == font["/BaseFont"]
        assert all(key in descriptor for key in ("/Flags", "/FontBBox", "/ItalicAngle", "/Ascent", "/Descent", "/StemV", "/CapHeight"))
    acroform = pdf.trailer["/Root"]["/AcroForm"]
    default_font = re.search(r"/([A-Za-z0-9]+)\s+[0-9.]+\s+Tf\b", str(acroform["/DA"]))
    assert default_font and "/" + default_font[1] in acroform["/DR"]["/Font"]
    with tempfile.TemporaryDirectory(prefix="pdf-html-oracle-") as directory:
        output = Path(directory) / "derived.html"
        completed = subprocess.run([args.php, str(root / "examples/01-derive-tagged-pdf.php"), str(fixture), str(output)], cwd=root, text=True, encoding="utf-8", capture_output=True, check=True)
        report = json.loads(completed.stdout)
        data = output.read_bytes()
        assert hashlib.sha256(data).hexdigest() == report["htmlSha256"]
        tree = check_html(data.decode("utf-8"))
        assert tree.attrib["lang"] == "fr-FR"
        assert tree.find("head/title").text == expected["title"]
        text = "".join(tree.itertext())
        assert expected["text"] in text
        assert expected["formText"] in text
        assert "Texte remplaçant" in text
        field = next(node for node in tree.iter("input") if node.attrib.get("name") == "Person.Name")
        assert field.attrib["value"] == "Ada" and "required" in field.attrib
        assert field.attrib["form"] in {node.attrib["id"] for node in tree.iter("form")}
        image = next(tree.iter("img"))
        assert image.attrib["alt"] == "A red pixel"
        assert image.attrib["width"] == image.attrib["height"] == "27"
        for asset in report["assets"]:
            assert hashlib.sha256((Path(directory) / asset["filename"]).read_bytes()).hexdigest() == asset["sha256"]
    cases = subprocess.run([args.php, str(root / "tests/export-derivation-cases.php")], cwd=root, text=True, encoding="utf-8", capture_output=True, check=True)
    for name, html in json.loads(cases.stdout).items():
        try:
            check_html(html)
        except AssertionError as error:
            raise AssertionError(f"Normative transformation case {name}: {error}") from error
    print("Verified PHP derivation against pypdf and HTML5 parsing: Unicode, roles, forms, navigation, assets and safe output")


if __name__ == "__main__":
    main()
