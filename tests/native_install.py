"""Install only into the disposable official checkout and training_test CI database."""
import os
from pathlib import Path
import subprocess
import zipfile

repo = Path(__file__).resolve().parents[1]
root = repo / '.ci/dolibarr/htdocs'
assert os.environ.get('CI') == 'true', 'Disposable CI environment required'
assert (root / 'install/step2.php').is_file()
documents = repo / '.ci/documents'
documents.mkdir(exist_ok=True)
config = {
    'dolibarr_main_url_root': 'http://127.0.0.1:8080',
    'dolibarr_main_document_root': str(root),
    'dolibarr_main_url_root_alt': '/custom',
    'dolibarr_main_document_root_alt': str(root / 'custom'),
    'dolibarr_main_data_root': str(documents),
    'dolibarr_main_db_host': os.environ['TRAINING_TEST_MYSQL_HOST'],
    'dolibarr_main_db_port': '3306',
    'dolibarr_main_db_name': 'training_test',
    'dolibarr_main_db_user': 'root',
    'dolibarr_main_db_pass': os.environ['TRAINING_TEST_MYSQL_PASSWORD'],
    'dolibarr_main_db_type': 'mysqli',
    'dolibarr_main_db_prefix': 'llx_',
    'dolibarr_main_db_character_set': 'utf8',
    'dolibarr_main_db_collation': 'utf8_unicode_ci',
    'dolibarr_main_authentication': 'dolibarr',
    'dolibarr_main_instance_unique_id': 'training-disposable-ci',
}
def php_string(value):
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"
(root / 'conf/conf.php').write_text('<?php\n' + ''.join(
    f'${name} = {php_string(value)};\n' for name, value in config.items()
))
# Extract the shipped archive, never copy the source module over it.
with zipfile.ZipFile(repo / 'dist/module_training-0.6.0.zip') as package:
    package.extractall(root / 'custom')
for args in [['step2.php', 'set', 'en_US'],
             ['step5.php', '0.0.0', '24.0.2', 'en_US', 'set',
              'admin', 'Training-CI-Only-84', 'Training-CI-Only-84', '1']]:
    result = subprocess.run(['php', *args], cwd=root / 'install', capture_output=True, text=True)
    (repo / '.ci' / (args[0] + '.log')).write_text(result.stdout + result.stderr)
    if result.returncode:
        raise RuntimeError(f'Native installer {args[0]} failed; see CI installer log')
assert (documents / 'install.lock').is_file(), 'Native installer must finish and lock installation'
print('Official Dolibarr installer completed with shipped ZIP extracted into custom/.')
