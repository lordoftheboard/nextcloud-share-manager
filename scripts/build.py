#!/usr/bin/env python3
"""Produce reproducible, dependency-free Nextcloud installation archives."""
from pathlib import Path
import gzip
import hashlib
import io
import subprocess
import tarfile
import time
import xml.etree.ElementTree as ET
import zipfile

root = Path(__file__).resolve().parent.parent
info = ET.parse(root / 'appinfo/info.xml').getroot()
app_id = info.findtext('id')
version = info.findtext('version')
epoch = int(subprocess.check_output(['git', '-C', str(root), 'log', '-1', '--format=%ct', '--', 'appinfo', 'lib', 'templates', 'js', 'css', 'img', 'LICENSE', 'INSTALL.md', 'scripts/build.py'], text=True))
files = []
for directory in ['appinfo', 'lib', 'templates', 'js', 'css', 'img']:
    for file in sorted((root / directory).rglob('*')):
        if file.is_file():
            files.append((file, file.relative_to(root).as_posix()))
files += [(root / 'LICENSE', 'LICENSE'), (root / 'INSTALL.md', 'README.md')]
output = root / 'dist'
output.mkdir(exist_ok=True)
stem = f'{app_id}-{version}'
with (output / f'{stem}.tar.gz').open('wb') as raw:
    with gzip.GzipFile(filename='', mode='wb', fileobj=raw, mtime=epoch) as compressed:
        with tarfile.open(fileobj=compressed, mode='w') as archive:
            for file, name in files:
                data = file.read_bytes()
                entry = tarfile.TarInfo(f'{app_id}/{name}')
                entry.size = len(data)
                entry.mtime = epoch
                entry.mode = 0o644
                entry.uid = entry.gid = 0
                archive.addfile(entry, io.BytesIO(data))
with zipfile.ZipFile(output / f'{stem}.zip', 'w', compression=zipfile.ZIP_DEFLATED) as archive:
    for file, name in files:
        entry = zipfile.ZipInfo(f'{app_id}/{name}', time.gmtime(epoch)[:6])
        entry.external_attr = 0o100644 << 16
        entry.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(entry, file.read_bytes())
checksums = []
for suffix in ['tar.gz', 'zip']:
    file = output / f'{stem}.{suffix}'
    checksums.append(f'{hashlib.sha256(file.read_bytes()).hexdigest()}  {file.name}\n')
(output / 'SHA256SUMS').write_text(''.join(checksums))
print(f'Created {stem}.tar.gz and {stem}.zip ({len(files)} application files).')
