"""Independent PDF parsing and real Poppler pixel checks for selected graphics exports."""
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
cases = json.loads(subprocess.check_output([args.php, str(root / "tests/export-graphics-cases.php")], cwd=root))
with tempfile.TemporaryDirectory(prefix="pdf-html-graphics-") as temporary:
    directory = Path(temporary)
    for name, case in cases.items():
        data = base64.b64decode(case["pdf"], validate=True)
        reader = PdfReader(io.BytesIO(data), strict=True)
        assert len(reader.pages) == 1
        assert reader.pages[0].mediabox.width == case["width"]
        assert reader.pages[0].mediabox.height == case["height"]
        assert "/OpenAction" not in reader.trailer["/Root"]
        assert "/AcroForm" not in reader.trailer["/Root"]
        pdf = directory / (name + ".pdf")
        pdf.write_bytes(data)
        subprocess.run([args.pdftoppm, "-r", "72", "-singlefile", str(pdf), str(directory / name)], check=True, capture_output=True)
        ppm = (directory / (name + ".ppm")).read_bytes()
        match = re.match(rb"P6\s+(\d+)\s+(\d+)\s+255\s", ppm)
        assert match, name
        width, height = map(int, match.groups())
        assert (width, height) == (case["width"], case["height"]), name
        pixels = ppm[match.end():]
        assert len(pixels) == width * height * 3

        def pixel(x, y):
            offset = (y * width + x) * 3
            return tuple(pixels[offset:offset + 3])

        if name in ("clipped-path", "inherited-form"):
            assert pixel(30, 170) == (255, 0, 0), (name, pixel(30, 170))
            assert pixel(80, 170) == (255, 255, 255), (name, pixel(80, 170))
            assert pixel(10, 10) == (255, 255, 255), name
        elif name == "rotation-unit":
            assert pixel(40, 40) == (255, 0, 0)
            assert pixel(120, 120) == (255, 255, 255)
        elif name == "text-position":
            ink = [(offset // 3 % width, offset // 3 // width) for offset in range(0, len(pixels), 3) if min(pixels[offset:offset + 3]) < 128]
            assert ink
            assert 50 <= min(x for x, _ in ink) < 55, min(x for x, _ in ink)
            assert max(x for x, _ in ink) < 75
        print("Verified isolated graphics with pypdf and Poppler:", name)
