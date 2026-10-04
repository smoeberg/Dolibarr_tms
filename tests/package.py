"""Validate the exact archive distributed to Dolibarr users."""
import importlib.util
from pathlib import Path, PurePosixPath
import tempfile
import zipfile

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('build_module', root / 'tools/build_module.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
with tempfile.TemporaryDirectory() as directory:
    rebuilt = builder.build(Path(directory) / 'rebuilt.zip')
    shipped = root / 'dist/module_training-0.7.0.zip'
    assert rebuilt.read_bytes() == shipped.read_bytes(), 'Committed archive differs from source'
    with zipfile.ZipFile(shipped) as archive:
        assert archive.testzip() is None
        names = archive.namelist()
        assert len(names) == len(set(names))
        assert 'training/core/modules/modTraining.class.php' in names
        assert 'training/sql/llx_training_sessions.sql' in names
        assert 'training/README.md' in names
        source = builder.MODULE
        assert set(names) == {'training/' + p.relative_to(source).as_posix() for p in source.rglob('*') if p.is_file()}
        for name in names:
            path = PurePosixPath(name)
            assert path.parts[0] == 'training' and '..' not in path.parts and not path.is_absolute()
            assert archive.read(name) == (source / Path(*path.parts[1:])).read_bytes()
print('PASS: installable module ZIP, safe paths, exact sources, reproducible build')
