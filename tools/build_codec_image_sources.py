"""Original Pillow/OpenJPEG associated-opacity fixtures, with explicit JP2 cdef type2."""
import argparse
import io
import json
from pathlib import Path
import struct
from PIL import Image

ROOT = Path(__file__).resolve().parents[1] / 'tests/Fixtures/codec-images'


def fixture(exact):
    width, height = 13, 7
    pixels = []
    for y in range(height):
        for x in range(width):
            alpha = [0, 85, 170, 255][(x + y) % 4] if exact else 128
            values = [((x * 7 + y * 13 + c * 11) % 86) * (alpha // 85) for c in range(3)] if exact else [(x * 7 + y * 13 + c * 11) % 128 for c in range(3)]
            pixels.append((*values, alpha))
    image = Image.new('RGBA', (width, height));image.putdata(pixels)
    output = io.BytesIO();image.save(output, format='JPEG2000', irreversible=False)
    data = bytearray(output.getvalue())
    at = 0;changed = False
    while at < len(data):
        size, kind = struct.unpack_from('>I4s', data, at)
        if kind == b'jp2h':
            child = at + 8
            while child < at + size:
                length, tag = struct.unpack_from('>I4s', data, child)
                if tag == b'cdef':
                    count = struct.unpack_from('>H', data, child + 8)[0]
                    for index in range(count):
                        channel, type_, association = struct.unpack_from('>HHH', data, child + 10 + index * 6)
                        if type_ == 1:
                            assert channel == 3 and association == 0
                            struct.pack_into('>H', data, child + 12 + index * 6, 2);changed = True
                child += length
        at += size
    assert changed
    expected = {'width': width, 'height': height, 'x0': 0, 'y0': 0, 'components': [{'width': width, 'height': height, 'precision': 8, 'signed': False, 'dx': 1, 'dy': 1, 'x0': 0, 'y0': 0, 'samples': [pixel[c] for pixel in pixels]} for c in range(4)]}
    return bytes(data), (json.dumps(expected, separators=(',', ':')) + '\n').encode()


def main():
    parser = argparse.ArgumentParser(description=__doc__);parser.add_argument('--check', action='store_true');args = parser.parse_args()
    for exact in [True, False]:
        stem = 'associated-exact' if exact else 'associated-rounded'
        for suffix, data in zip(['.jp2', '.jp2.json'], fixture(exact)):
            path = ROOT / (stem + suffix)
            if args.check:
                assert path.read_bytes() == data, path
            else:
                path.write_bytes(data)
    rgb = Image.new('RGB', (13, 7))
    rgb.putdata([((x * 17 + y * 21) % 256, (x * 31 + y * 7) % 256, (x * 5 + y * 41) % 256) for y in range(7) for x in range(13)])
    output = io.BytesIO();rgb.save(output, format='JPEG', quality=91, subsampling=0)
    if args.check:
        assert (ROOT / 'rgb.jpg').read_bytes() == output.getvalue()
    else:
        (ROOT / 'rgb.jpg').write_bytes(output.getvalue())
    print('Verified original Pillow JPEG and OpenJPEG premultiplied-opacity inputs')


if __name__ == '__main__':
    main()
