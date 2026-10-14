"""Independent PDF/HTML5/PNG oracle for framed inline images and dimensioned fallbacks."""
from pathlib import Path
import argparse
import hashlib
import json
import struct
import subprocess
import tempfile
import zlib

import html5lib
from pypdf import PdfReader
from pypdf.generic import ContentStream


def png_samples(data):
    assert data[:8] == b'\x89PNG\r\n\x1a\n'
    offset = 8
    compressed = bytearray()
    header = None
    while offset < len(data):
        size = struct.unpack_from('>I', data, offset)[0]
        kind = data[offset + 4:offset + 8]
        payload = data[offset + 8:offset + 8 + size]
        crc = struct.unpack_from('>I', data, offset + 8 + size)[0]
        assert zlib.crc32(kind + payload) & 0xffffffff == crc
        if kind == b'IHDR':
            header = struct.unpack('>IIBBBBB', payload)
        if kind == b'IDAT':
            compressed.extend(payload)
        offset += size + 12
    assert header is not None and header[2] == 8 and header[3] in (0, 2)
    width, height = header[:2]
    components = 1 if header[3] == 0 else 3
    decoded = zlib.decompress(compressed)
    stride = width * components
    assert len(decoded) == height * (stride + 1)
    samples = bytearray()
    for row in range(height):
        assert decoded[row * (stride + 1)] == 0
        samples.extend(decoded[row * (stride + 1) + 1:(row + 1) * (stride + 1)])
    return bytes(samples)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    fixture = root / 'tests/Fixtures/inline-images.pdf'
    manifest = json.loads((root / 'tests/Fixtures/inline-manifest.json').read_text())
    assert hashlib.sha256(fixture.read_bytes()).hexdigest() == manifest['sha256']
    pdf = PdfReader(fixture)
    operations = ContentStream(pdf.pages[0].get_contents(), pdf).operations
    images = [operands for operands, operator in operations if operator == b'INLINE IMAGE']
    assert len(images) == len(manifest['images']) == 12
    assert images[0]['data'].hex() == manifest['graySamplesHex']
    assert images[1]['data'].hex() == manifest['rgbSamplesHex']
    assert hashlib.sha256(images[6]['data']).hexdigest() == manifest['jpegSha256']
    assert 'Text after all inline images' in pdf.pages[0].extract_text()
    with tempfile.TemporaryDirectory(prefix='pdf-html-inline-oracle-') as directory:
        output = Path(directory) / 'derived.html'
        process = subprocess.run([args.php, str(root / 'examples/01-derive-tagged-pdf.php'), str(fixture), str(output)], cwd=root, capture_output=True, text=True, encoding='utf-8', check=True)
        report = json.loads(process.stdout)
        parser = html5lib.HTMLParser(namespaceHTMLElements=False)
        html = parser.parse(output.read_text(encoding='utf-8'))
        assert not parser.errors, parser.errors
        assert 'Text after all inline images' in ''.join(html.itertext())
        output_images = list(html.iter('img'))
        assert len(output_images) == 12
        assert [node.attrib['alt'] for node in output_images] == manifest['images']
        assert all([int(node.attrib['width']), int(node.attrib['height'])] == manifest['logicalCssPixels'] for node in output_images)
        samples = set()
        jpeg_hash = None
        for asset in report['assets']:
            data = (Path(directory) / asset['filename']).read_bytes()
            assert hashlib.sha256(data).hexdigest() == asset['sha256']
            if asset['mediaType'] == 'image/png':
                samples.add(png_samples(data).hex())
            elif asset['mediaType'] == 'image/jpeg':
                jpeg_hash = asset['sha256']
        assert samples == {manifest['graySamplesHex'], manifest['rgbSamplesHex']}
        assert jpeg_hash == manifest['jpegSha256']
        assert sum(item['code'] == 'image-placeholder' for item in report['diagnostics']) == 5
    print('Verified 12 inline images using pypdf, HTML5, PNG CRC/sample decoding and JPEG bytes; five dimensioned placeholders')


if __name__ == '__main__':
    main()
