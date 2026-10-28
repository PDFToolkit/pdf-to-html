"""Check exact PDF samples, native PNG bytes, Pillow decoding and Poppler source pixels."""
import argparse
import base64
import hashlib
import io
import json
from pathlib import Path
import struct
import subprocess
import tempfile
import zlib

import html5lib
from PIL import Image
from pypdf import PdfReader
from validate_inline_derivation import png_samples

ROOT = Path(__file__).resolve().parents[1]


def run(command):
    result = subprocess.run([str(value) for value in command], cwd=ROOT, capture_output=True, text=True, timeout=60)
    assert result.returncode == 0, (command, result.stdout, result.stderr)
    return result


def values(samples, case):
    width, height, components, bits = (case[key] for key in ('width', 'height', 'components', 'bits'))
    stride = (width * components * bits + 7) // 8
    result = []
    for y in range(height):
        for x in range(width):
            pixel = []
            for channel in range(components):
                bit = (x * components + channel) * bits
                offset = y * stride + bit // 8
                value = int.from_bytes(samples[offset:offset + 2], 'big') if bits == 16 else (samples[offset] >> (8 - bits - bit % 8)) & ((1 << bits) - 1)
                pixel.append(value)
            result.append(tuple(pixel))
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--pdftoppm', default='pdftoppm')
    parser.add_argument('--report', type=Path, required=True)
    args = parser.parse_args()
    fixture = ROOT / 'tests/Fixtures/sample-depths.pdf'
    manifest = json.loads(fixture.with_suffix('.json').read_text())
    assert hashlib.sha256(fixture.read_bytes()).hexdigest() == manifest['sha256']
    run([args.qpdf, '--check', fixture])
    pdf = PdfReader(fixture, strict=True)
    assert len(pdf.pages) == len(manifest['cases']) == 17
    (ROOT / '_build').mkdir(exist_ok=True)
    report = {'sourceSha256': manifest['sha256'], 'cases': [], 'raster16BitPolicy': 'Exact 16-bit samples are checked in PDF and PNG bytes; Poppler and Pillow RGB previews use the high byte.'}
    with tempfile.TemporaryDirectory(prefix='sample-images-', dir=ROOT / '_build') as temporary:
        directory = Path(temporary)
        run([args.php, ROOT / 'tools/export-sample-images.php', directory])
        exported = json.loads((directory / 'report.json').read_text())
        html_parser = html5lib.HTMLParser(namespaceHTMLElements=False)
        html = html_parser.parse((directory / 'derived.html').read_text())
        assert not html_parser.errors, html_parser.errors
        images = list(html.iter('img'))
        assert len(images) == 17
        assert sum(item['code'] == 'image-placeholder' for item in exported['diagnostics']) == 3
        scale = 8
        run([args.pdftoppm, '-r', str(72 * scale), '-png', fixture, directory / 'source'])
        for page, case, element in zip(pdf.pages, manifest['cases'], images):
            expected = base64.b64decode(case['samples'], validate=True)
            if case['name'].startswith('inline-'):
                # pypdf exposes the independently parsed page stream. Its generic
                # EI heuristic rejects some legal short binary Flate payloads;
                # zlib's EOD is the independent exact boundary used here.
                content = page.get_contents().get_data()
                declaration, encoded = content.split(b' ID ', 1)
                assert f'/W {case["width"]} /H {case["height"]} /BPC {case["bits"]}'.encode() in declaration
                decoder = zlib.decompressobj()
                source_samples = decoder.decompress(encoded)
                assert decoder.eof and decoder.unused_data == b' EI Q EMC'
            else:
                source = page['/Resources']['/XObject']['/I']
                source_samples = source.get_data()
                if case['fallback']:
                    assert '/' + case['fallback'] in source
            assert source_samples == expected, case['name']
            assert element.attrib['alt'] == case['name']
            assert [int(element.attrib[key]) for key in ('width', 'height')] == case['logicalCssPixels']
            item = {'name': case['name'], 'pdfSamples': True, 'html5': True, 'logicalDimensions': True}
            if case['fallback']:
                assert element.attrib['src'] == 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', case['name']
                item['explicitFallback'] = case['fallback']
            else:
                location = element.attrib['src']
                data = base64.b64decode(location.removeprefix('data:image/png;base64,'), validate=True) if location.startswith('data:image/png;base64,') else (directory / location).read_bytes()
                assert data[24:26] == bytes([case['bits'], 0 if case['components'] == 1 else 2])
                assert png_samples(data) == expected, case['name']
                samples = values(expected, case)
                with Image.open(io.BytesIO(data)) as native:
                    native.load()
                    assert native.size == (case['width'], case['height'])
                    if case['bits'] == 16 and case['components'] == 1:
                        assert list(native.get_flattened_data()) == [pixel[0] for pixel in samples], case['name']
                    else:
                        expected_rgb = [tuple((value >> 8 if case['bits'] == 16 else round(value * 255 / ((1 << case['bits']) - 1))) for value in (pixel * 3 if len(pixel) == 1 else pixel)) for pixel in samples]
                        assert list(native.convert('RGB').get_flattened_data()) == expected_rgb, case['name']
                with Image.open(directory / f"source-{case['page']:02d}.png") as raster:
                    raster = raster.convert('RGB')
                    assert raster.size == (case['width'] * scale, case['height'] * scale)
                    worst = 0
                    for index, pixel in enumerate(samples):
                        expected_rgb = tuple(value >> 8 if case['bits'] == 16 else round(value * 255 / ((1 << case['bits']) - 1)) for value in (pixel * 3 if len(pixel) == 1 else pixel))
                        actual_rgb = raster.getpixel(((index % case['width']) * scale + scale // 2, (index // case['width']) * scale + scale // 2))
                        worst = max(worst, max(abs(a - b) for a, b in zip(actual_rgb, expected_rgb)))
                    assert worst <= 1, (case['name'], worst)
                item.update(pngSamplesExact=True, pillowPixels=True, popplerMaximumChannelDifference=worst, pngSha256=hashlib.sha256(data).hexdigest())
            report['cases'].append(item)
        report.update(ok=True, nativeCases=14, explicitFallbacks=3, htmlSha256=exported['htmlSha256'])
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(report, indent=2) + '\n')
    print('Validated 14 exact gray/RGB PNG paths and three explicit masked/Decode fallbacks with pypdf, qpdf, HTML5, Pillow and Poppler')


if __name__ == '__main__':
    main()
