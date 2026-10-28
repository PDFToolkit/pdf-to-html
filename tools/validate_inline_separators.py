"""Independent encoded-sample oracle for the EC3 issue 20 ID separator exception."""
from pathlib import Path
import argparse
import base64
import io
import json
import subprocess
import tempfile

import html5lib
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
        for name in ('rgb-asciihex', 'rgb-ascii85'):
            image = next(image for image in images if image.attrib['alt'] == name)
            location = image.attrib['src']
            data = (base64.b64decode(location.split(',', 1)[1], validate=True)
                if location.startswith('data:image/png;base64,') else (directory / location).read_bytes())
            assert png_samples(data).hex() == manifest['rgbSamplesHex']
        assert len(report['assets']) == 4
    print('Verified EC3 issue 20: two separator-free ASCII inline images, exact RGB samples, all 12 image mappings and following text using independent pypdf/HTML5/PNG checks.')


if __name__ == '__main__':
    main()
