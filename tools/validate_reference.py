"""Verify the retained CC-BY-4.0 source without changing its bytes."""
from pathlib import Path
import hashlib
import json

from pypdf import PdfReader

root = Path(__file__).resolve().parents[1]
record = json.loads((root / "resources/reference.json").read_text(encoding="utf-8"))
source = root / record["source"]
data = source.read_bytes()
assert source.name == record["filename"]
assert len(data) == record["bytes"]
assert hashlib.sha256(data).hexdigest() == record["sha256"]
assert len(PdfReader(source).pages) == record["physicalPdfPages"]
assert record["license"] == "CC-BY-4.0"
print("Verified original PDF Association reference: 74 pages, identity and CC-BY attribution")
