"""Compare isolated widget appearances with independently selected native PDF appearances."""
import argparse
import base64
import io
import json
from pathlib import Path
import re
import subprocess
import tempfile

from pypdf import PdfReader, PdfWriter
from pypdf.generic import DictionaryObject, IndirectObject, NameObject, StreamObject


def rasterize(pdf, prefix, executable):
    subprocess.run([executable, '-r', '72', '-singlefile', str(pdf), str(prefix)], check=True, capture_output=True)
    ppm = prefix.with_suffix('.ppm').read_bytes()
    match = re.match(rb'P6\s+(\d+)\s+(\d+)\s+255\s', ppm)
    assert match, pdf
    width, height = map(int, match.groups())
    pixels = ppm[match.end():]
    assert len(pixels) == width * height * 3, pdf
    return width, height, pixels


def validate(directory, args):
    root = Path(__file__).resolve().parents[1]
    cases = json.loads(subprocess.check_output([args.php, str(root / 'tests/Rendering/export-appearance-cases.php')], cwd=root))
    records = []
    for name, case in cases.items():
        source = PdfReader(io.BytesIO(base64.b64decode(case['source'], validate=True)), strict=True)
        exported = base64.b64decode(case['pdf'], validate=True)
        isolated = PdfReader(io.BytesIO(exported), strict=True)
        assert len(isolated.pages) == 1
        assert isolated.pages[0].mediabox.width == case['width']
        assert isolated.pages[0].mediabox.height == case['height']
        assert '/AcroForm' not in isolated.trailer['/Root']
        assert '/OpenAction' not in isolated.trailer['/Root']
        # Keep the source annotation machinery. Only select the tested N/D/R stream
        # as N; Poppler itself computes the appearance Matrix/BBox-to-Rect mapping.
        control = PdfWriter()
        control.clone_document_from_reader(source)
        widget = control.pages[0]['/Annots'][0].get_object()
        appearances = widget['/AP']
        selected = appearances.raw_get('/' + case['state'])
        value = selected.get_object() if isinstance(selected, IndirectObject) else selected
        if isinstance(value, DictionaryObject) and not isinstance(value, StreamObject):
            selected = value.raw_get(widget['/AS'])
        appearances[NameObject('/N')] = selected
        control_pdf = directory / (name + '-native.pdf')
        with control_pdf.open('wb') as stream:
            control.write(stream)
        isolated_pdf = directory / (name + '-isolated.pdf')
        isolated_pdf.write_bytes(exported)
        native_width, native_height, native = rasterize(control_pdf, directory / (name + '-native'), args.pdftoppm)
        width, height, pixels = rasterize(isolated_pdf, directory / (name + '-isolated'), args.pdftoppm)
        left, bottom, right, top = map(int, widget['/Rect'])
        assert (native_width, native_height) == (200, 200)
        assert (width, height) == (right - left, top - bottom)
        crop = b''.join(native[(row * native_width + left) * 3:(row * native_width + right) * 3]
                        for row in range(native_height - top, native_height - bottom))
        assert pixels == crop, name
        expected = {'N': (255, 0, 0), 'D': (0, 0, 255), 'R': (0, 255, 0)}[case['state']]
        assert tuple(pixels[:3]) == expected, name
        assert any(max(pixels[offset:offset + 3]) < 64 for offset in range(0, len(pixels), 3)), ('text missing', name)
        subprocess.run([args.pdftoppm, '-r', '72', '-singlefile', '-png', str(isolated_pdf), str(directory / name)], check=True, capture_output=True)
        records.append({'case': name, 'width': width, 'height': height, 'exactNativeAnnotationPixels': True,
                        'independentStateSelection': case['state'], 'preview': str(directory / (name + '.png'))})
    report = {'passed': True, 'cases': records, 'independentTools': ['pypdf', 'Poppler'],
              'scope': 'Six normal/down/rollover appearances at two widget sizes, compared byte-for-byte to the native annotation rendering; source resources, text, transformed BBox clipping and Rect scaling retained.'}
    (directory / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--pdftoppm', default='pdftoppm')
    parser.add_argument('--work-dir', type=Path)
    args = parser.parse_args()
    if args.work_dir:
        args.work_dir.mkdir(parents=True, exist_ok=True)
        validate(args.work_dir.resolve(), args)
    else:
        with tempfile.TemporaryDirectory(prefix='pdf-html-appearances-') as temporary:
            validate(Path(temporary), args)
