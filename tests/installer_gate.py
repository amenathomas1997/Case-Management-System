"""Exercise the installer gate without loading production configuration or a database."""
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='cms-installer-audit-') as directory:
    path = Path(directory) / 'install.php'
    path.write_text((root / 'dist/install.php').read_text())
    def render():
        return subprocess.run(['php', '-r', "$_SERVER['REQUEST_METHOD']='GET'; require $argv[1];", str(path)], capture_output=True, text=True, check=True).stdout
    assert 'Installer disabled.' in render()
    print('PASS: Installer disabled without an administrator-created marker')
    (Path(directory) / 'install.php.enabled').touch()
    form = render()
    assert '<form' in form
    print('PASS: Explicitly enabled fresh installer displays its form')
    # 3.0.28: the optional contact and privacy-notice fields, each with a hint and none required.
    for name in ('support_phone', 'privacy_operator', 'privacy_address', 'privacy_law', 'privacy_hosting'):
        assert f'name="{name}"' in form and 'required' not in form.split(f'name="{name}"')[1].split('>')[0], name
    print('PASS: Installer offers the optional support phone and privacy-notice fields')
    (Path(directory) / 'install.lock').touch()
    assert 'Installer is locked' in render()
    print('PASS: Installation lock takes precedence over the enable marker')
