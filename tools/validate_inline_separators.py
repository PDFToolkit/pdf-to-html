"""Independent encoded-sample oracle for the EC3 issue 20 ID separator exception."""
from pathlib import Path
import argparse
import base64
import hashlib
import io
import json
import struct
import subprocess
import tempfile

import html5lib
from PIL import Image
from pypdf import PdfReader, PdfWriter
from pypdf.generic import DecodedStreamObject, NameObject

from build_inline_fixture import fixture
from validate_inline_derivation import png_samples


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--work-dir', type=Path)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    original, metadata = fixture()
    manifest = json.loads(metadata)
    writer = PdfWriter(clone_from=PdfReader(io.BytesIO(original)))
    content = writer.pages[0].get_contents().get_data()
    for name in (b'/AHx', b'/A85'):
        before = b'/F ' + name + b' ID '
        assert content.count(before) == 1
        content = content.replace(before, b'/F ' + name + b' ID')
    stream = DecodedStreamObject()
    stream.set_data(content)
    writer.pages[0][NameObject('/Contents')] = writer._add_object(stream)
    with tempfile.TemporaryDirectory(prefix='pdf-html-inline-separators-') as temporary:
        directory = (args.work_dir or Path(temporary)).resolve()
        directory.mkdir(parents=True, exist_ok=True)
        source = directory / 'inline-separators.pdf'
        writer.write(source)
        # Inspect the actual independent PDF graph. Pixel expectations come from
        # Python's encoders and the independently checked original fixture.
        reread = PdfReader(source)
        assert reread.pages[0].get_contents().get_data() == content
        output = directory / 'derived.html'
        result = subprocess.run([args.php, str(root / 'examples/01-derive-tagged-pdf.php'),
            str(source), str(output)], cwd=root, capture_output=True, text=True, check=True)
        report = json.loads(result.stdout)
        parser = html5lib.HTMLParser(namespaceHTMLElements=False)
        html = parser.parse(output.read_text())
        assert not parser.errors, parser.errors
        images = list(html.iter('img'))
        assert [image.attrib['alt'] for image in images] == manifest['images']
        assert 'Text after all inline images' in ''.join(html.itertext())
        assert all([int(image.attrib['width']), int(image.attrib['height'])] == manifest['logicalCssPixels'] for image in images)
        assets = {asset['filename']: asset for asset in report['assets']}
        assert len(assets) == len(report['assets']), 'No duplicate asset records'
        by_hash = {asset['sha256']: asset for asset in report['assets']}
        assert len(by_hash) == len(assets), 'Identical bytes must share an asset'
        referenced = set()
        png_groups = {}
        fallback_sources = set()
        for image in images:
            name, location = image.attrib['alt'], image.attrib['src']
            if location.startswith('data:'):
                header, encoded = location.split(',', 1)
                assert header.endswith(';base64')
                data = base64.b64decode(encoded, validate=True)
                asset = by_hash[hashlib.sha256(data).hexdigest()]
                assert header == 'data:' + asset['mediaType'] + ';base64'
                location = asset['filename']
                assert (directory / location).read_bytes() == data
            else:
                asset = assets[location]
                data = (directory / location).read_bytes()
            referenced.add(location)
            assert hashlib.sha256(data).hexdigest() == asset['sha256']
            if name == 'gray-jpeg':
                assert asset['mediaType'] == 'image/jpeg'
                assert asset['sha256'] == manifest['jpegSha256']
            elif name in ('cmyk-placeholder', 'mask-placeholder', 'indexed-placeholder'):
                assert asset['mediaType'] == 'image/gif'
                with Image.open(io.BytesIO(data)) as placeholder:
                    assert placeholder.size == (1, 1)
                    assert placeholder.convert('RGBA').getpixel((0, 0))[3] == 0
                fallback_sources.add(location)
            else:
                assert asset['mediaType'] == 'image/png'
                if name.startswith('rgb-'):
                    expected = (manifest['rgbSamplesHex'], (1, 1, 8, 2))
                elif name == 'two-bit-placeholder':
                    # The historical fixture label remains unchanged. This is
                    # now a native packed-gray image, not a fourth fallback.
                    expected = ('1b', (4, 1, 2, 0))
                else:
                    assert name in ('gray-raw', 'gray-runlength', 'lzw-placeholder')
                    expected = (manifest['graySamplesHex'], (4, 1, 8, 0))
                assert (png_samples(data).hex(), struct.unpack('>IIBB', data[16:26])) == expected, name
                png_groups.setdefault(expected, set()).add(location)
        assert len(png_groups) == 3 and all(len(locations) == 1 for locations in png_groups.values())
        assert referenced == set(assets), 'No missing or unreferenced asset'
        assert len(fallback_sources) == 1
        assert sum(item['code'] == 'image-placeholder' for item in report['diagnostics']) == 3
        assert len(assets) == len(png_groups) + len(fallback_sources) + 1, 'Three PNG groups, shared fallback and original JPEG'
    print('Verified EC3 issue 20: two separator-free ASCII inline images, all 12 mappings, three exact PNG groups including packed gray, original JPEG, three shared placeholders and following text using independent pypdf/HTML5/PNG/Pillow checks.')


if __name__ == '__main__':
    main()
