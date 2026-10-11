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
    assert '<form' in render()
    print('PASS: Explicitly enabled fresh installer displays its form')
    (Path(directory) / 'install.lock').touch()
    assert 'Installer is locked' in render()
    print('PASS: Installation lock takes precedence over the enable marker')
