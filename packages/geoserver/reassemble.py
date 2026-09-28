#!/usr/bin/env python3
"""Reassemble the preserved GeoServer ZIP offline, validating every part and output."""
import hashlib
import json
import os
from pathlib import Path
import tempfile
import zipfile

root = Path(__file__).resolve().parent
manifest = json.loads((root / 'manifest.json').read_text())
target = root / manifest['archive']
if target.exists():
    raise SystemExit('Output already exists; move it before reassembling: ' + str(target))
fd, temporary = tempfile.mkstemp(prefix='.geoserver-', dir=root)
try:
    digest = hashlib.sha256()
    total = 0
    with os.fdopen(fd, 'wb') as output:
        for part in manifest['parts']:
            data = (root / part['file']).read_bytes()
            if len(data) != part['bytes'] or hashlib.sha256(data).hexdigest() != part['sha256']:
                raise SystemExit('Missing or damaged archive part: ' + part['file'])
            output.write(data)
            digest.update(data)
            total += len(data)
    if total != manifest['bytes'] or digest.hexdigest() != manifest['sha256']:
        raise SystemExit('Reassembled ZIP checksum mismatch')
    with zipfile.ZipFile(temporary) as archive:
        if archive.testzip() is not None:
            raise SystemExit('ZIP integrity check failed')
    # Exclusive publication avoids overwriting a file created while verification ran.
    os.link(temporary, target)
    print('Verified:', target.name)
finally:
    os.unlink(temporary)
