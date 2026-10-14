"""Verify libtiff CCITT strips and native HTML fallbacks against pypdf, HTML5 and Poppler."""
from pathlib import Path
import argparse
import base64
import hashlib
import json
import subprocess
import tempfile

import html5lib
from pypdf import PdfReader
from pypdf.generic import ContentStream


def pbm(data):
    offset, tokens = 0, []
    while len(tokens) < 3:
        while data[offset] in b' \t\r\n':
            offset += 1
        if data[offset] == 35:
            offset = data.index(b'\n', offset) + 1
            continue
        end = offset
        while data[end] not in b' \t\r\n':
            end += 1
        tokens.append(data[offset:end])
        offset = end
    assert tokens[0] == b'P4'
    if data[offset:offset + 2] == b'\r\n':
        offset += 2
    else:
        offset += 1
    return int(tokens[1]), int(tokens[2]), data[offset:]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--pdftoppm', default='pdftoppm')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    manifest = json.loads((root / 'tests/Fixtures/inline-fax-cases.json').read_text())
    fixture = root / 'tests/Fixtures/inline-fax.pdf'
    pdf = PdfReader(fixture)
    assert len(pdf.pages) == len(manifest['cases']) + 1
    for page, case in zip(pdf.pages, manifest['cases']):
        images = [o for o, operator in ContentStream(page.get_contents(), pdf).operations if operator == b'INLINE IMAGE']
        assert len(images) == 1
        expected = base64.b64decode(case['encoded'])
        assert hashlib.sha256(expected).hexdigest() == case['encodedSha256']
        # pypdf 6.20.1 retains the PDF data/EI separating space for CCITT. Native
        # exact-endpoint checks are separate from this parser representation.
        assert images[0]['data'] in (expected, expected + b' ')
    with tempfile.TemporaryDirectory(prefix='pdf-html-fax-oracle-') as directory:
        temporary = Path(directory)
        # Sample every original pixel at 8x scale. This avoids Splash's edge
        # resampling/dithering at a one-device-pixel image boundary.
        scale = 8
        subprocess.run([args.pdftoppm, '-r', str(72 * scale), '-mono', '-f', '1', '-l', str(len(manifest['cases'])), str(fixture), str(temporary / 'fax')], capture_output=True, check=True)
        for index, case in enumerate(manifest['cases'], 1):
            width, height, bitmap = pbm((temporary / f'fax-{index}.pbm').read_bytes())
            assert [width, height] == [case['width'] * scale, case['height'] * scale]
            expected = base64.b64decode(case['bitmap'])
            stride = (width + 7) // 8
            expected_stride = (case['width'] + 7) // 8
            for y in range(case['height']):
                for x in range(case['width']):
                    a, b = x * scale + scale // 2, y * scale + scale // 2
                    actual_black = (bitmap[b * stride + a // 8] >> (7 - a % 8)) & 1
                    expected_white = (expected[y * expected_stride + x // 8] >> (7 - x % 8)) & 1
                    assert actual_black != expected_white, (case['name'], x, y)
        output = temporary / 'derived.html'
        report = json.loads(subprocess.run([args.php, str(root / 'examples/01-derive-tagged-pdf.php'), str(fixture), str(output)], cwd=root, capture_output=True, text=True, encoding='utf-8', check=True).stdout)
        parser = html5lib.HTMLParser(namespaceHTMLElements=False)
        html = parser.parse(output.read_text(encoding='utf-8'))
        assert not parser.errors, parser.errors
        images = list(html.iter('img'))
        assert len(images) == len(manifest['cases'])
        for image, case in zip(images, manifest['cases']):
            assert image.attrib['alt'] == case['name']
            assert int(image.attrib['width']) == round(case['width'] * 4 / 3)
            assert int(image.attrib['height']) == round(case['height'] * 4 / 3)
        assert 'Following the fax images' in ''.join(html.itertext())
        assert sum(diagnostic['code'] == 'image-placeholder' for diagnostic in report['diagnostics']) == len(images)
    print('Verified seven libtiff CCITT encodings, exact framing, HTML5 fallbacks and every pixel independently with Poppler')


if __name__ == '__main__':
    main()
