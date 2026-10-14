"""Independently verify graphics context through pypdf and complete Poppler pixel comparisons."""
import argparse
import base64
import io
import json
from pathlib import Path
import re
import subprocess
import tempfile

from pypdf import PdfReader

parser = argparse.ArgumentParser()
parser.add_argument("--php", default="php")
parser.add_argument("--pdftoppm", default="pdftoppm")
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
cases = json.loads(subprocess.check_output([args.php, str(root / "tests/export-graphics-context-cases.php")], cwd=root))

with tempfile.TemporaryDirectory(prefix="pdf-html-graphics-context-") as temporary:
    directory = Path(temporary)
    for name, case in cases.items():
        images = {}
        readers = {}
        for variant in ("source", "pdf"):
            data = base64.b64decode(case[variant], validate=True)
            readers[variant] = PdfReader(io.BytesIO(data), strict=True)
            assert len(readers[variant].pages) == 1, (name, variant)
            page = readers[variant].pages[0]
            assert (page.mediabox.width, page.mediabox.height) == (case["width"], case["height"]), name
            pdf = directory / (name + "-" + variant + ".pdf")
            pdf.write_bytes(data)
            prefix = pdf.with_suffix("")
            subprocess.run([args.pdftoppm, "-r", "72", "-singlefile", str(pdf), str(prefix)], capture_output=True, check=True)
            ppm = prefix.with_suffix(".ppm").read_bytes()
            match = re.match(rb"P6\s+(\d+)\s+(\d+)\s+255\s", ppm)
            assert match, (name, variant)
            width, height = map(int, match.groups())
            assert (width, height) == (case["width"], case["height"]), name
            pixels = ppm[match.end():]
            assert len(pixels) == width * height * 3, (name, variant)
            images[variant] = pixels

        output = readers["pdf"]
        assert "/OpenAction" not in output.trailer["/Root"]
        assert "/AcroForm" not in output.trailer["/Root"]
        if name == "nested-artifact":
            # Removing the outer group's nested Artifact must not remove its real rectangle.
            red = sum(images["pdf"][i:i + 3] == b"\xff\x00\x00" for i in range(0, len(images["pdf"]), 3))
            yellow = sum(images["pdf"][i:i + 3] == b"\xff\xff\x00" for i in range(0, len(images["pdf"]), 3))
            assert red == 400 and yellow == 0, (name, red, yellow)
            assert images["source"] != images["pdf"], name
        else:
            assert images["source"] == images["pdf"], "Original context changed: " + name
        if name in ("inherited-text-clip", "form-null-external-file"):
            red = sum(images["pdf"][i:i + 3] == b"\xff\x00\x00" for i in range(0, len(images["pdf"]), 3))
            assert 0 < red < 1000, (name, red)
        elif name == "page-transparency-group":
            assert output.pages[0]["/Group"]["/CS"] == "/DeviceCMYK"
            assert images["pdf"] != b"\xff\xff\xff" * width * height, name
        elif name == "hidden-optional-content":
            properties = output.trailer["/Root"]["/OCProperties"]
            assert properties["/D"]["/OFF"][0].get_object()["/Name"] == "Hidden layer"
            blue = sum(images["pdf"][i:i + 3] == b"\x00\x00\xff" for i in range(0, len(images["pdf"]), 3))
            red = sum(images["pdf"][i:i + 3] == b"\xff\x00\x00" for i in range(0, len(images["pdf"]), 3))
            assert blue == 400 and red == 0, (name, blue, red)
        elif name == "pdf17-standard-font":
            assert base64.b64decode(case["pdf"]).startswith(b"%PDF-1.7"), name
        elif name.startswith("standalone-form-"):
            forms = [reference.get_object() for reference in output.pages[0]["/Resources"]["/XObject"].values()]
            assert len(forms) == 1 and forms[0]["/Subtype"] == "/Form", name
            form = forms[0]
            assert list(form["/BBox"]) == [0, 0, 10, 10] and "/Matrix" not in form, name
            if name == "standalone-form-group":
                assert form["/Group"]["/CS"] == "/DeviceCMYK", name
                assert float(form["/Resources"]["/ExtGState"]["/Alpha"]["/ca"]) == 0.5, name
                assert images["pdf"] != b"\xff\xff\xff" * width * height, name
            elif name == "standalone-form-oc":
                assert form["/OC"]["/Type"] == "/OCG", name
                assert output.trailer["/Root"]["/OCProperties"]["/D"]["/OFF"][0].get_object()["/Name"] == "Hidden standalone Form", name
                assert images["pdf"] == b"\xff\xff\xff" * width * height, name
            elif name == "standalone-form-viewport":
                assert list(output.pages[0].mediabox) == [8, -40, 16, -16], name
                assert (width, height) == (8, 24), name
        print("Verified original graphics context with pypdf and Poppler:", name)
