#!/usr/bin/env python3
"""Build a deterministic Dolibarr external-module archive using stdlib only."""
from pathlib import Path
import argparse
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / 'htdocs/custom/training'

def build(output=None):
    descriptor = (MODULE / 'core/modules/modTraining.class.php').read_text()
    version = re.search(r"\$this->version = '([0-9]+\.[0-9]+\.[0-9]+)'", descriptor).group(1)
    destination = Path(output) if output else ROOT / f'dist/module_training-{version}.zip'
    destination.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(destination, 'w', compression=zipfile.ZIP_STORED) as archive:
        for path in sorted(MODULE.rglob('*')):
            if path.is_symlink():
                raise ValueError(f'Symlink is not allowed: {path}')
            if path.is_file():
                relative = path.relative_to(MODULE)
                if any(part.startswith('.') for part in relative.parts):
                    raise ValueError(f'Hidden build input: {path}')
                info = zipfile.ZipInfo('training/' + relative.as_posix(), (2026, 1, 1, 0, 0, 0))
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                archive.writestr(info, path.read_bytes())
    return destination

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output')
    print(build(parser.parse_args().output))
