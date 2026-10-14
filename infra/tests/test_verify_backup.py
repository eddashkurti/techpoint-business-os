"""Meaningful restore-boundary tests: integrity, path traversal and links."""
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest

MODULE_PATH = Path(__file__).resolve().parents[1] / "scripts" / "verify-backup.py"
spec = importlib.util.spec_from_file_location("verify_backup", MODULE_PATH)
verify_backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verify_backup)


class BackupBoundaryTests(unittest.TestCase):
    def setUp(self):
        scratch_root = Path(__file__).resolve().parent / ".work"
        scratch_root.mkdir(exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=scratch_root)
        # The cleanup is restricted to a newly created child of this workspace.
        self.assertEqual(Path(self.temporary.name).resolve().parent, scratch_root.resolve())
        self.addCleanup(self.temporary.cleanup)
        self.bundle = Path(self.temporary.name)
        (self.bundle / "database.sql.gz").write_bytes(b"test database bytes")
        (self.bundle / "app_key").write_text("base64:test", encoding="ascii")
        (self.bundle / "table-counts.tsv").write_text("products\t3\n", encoding="ascii")
        manifest = {"format": 1, "files": sorted(verify_backup.EXPECTED - {"manifest.json"})}
        (self.bundle / "manifest.json").write_text(json.dumps(manifest), encoding="utf-8")
        self.write_archive("./private/invoice.pdf")

    def write_archive(self, name, kind=tarfile.REGTYPE):
        with tarfile.open(self.bundle / "attachments.tar.gz", "w:gz") as archive:
            member = tarfile.TarInfo(name)
            member.type = kind
            if kind == tarfile.REGTYPE:
                payload = b"private attachment"
                member.size = len(payload)
                archive.addfile(member, io.BytesIO(payload))
            else:
                member.linkname = "/etc/passwd"
                archive.addfile(member)
        checksums = []
        for filename in sorted(verify_backup.EXPECTED):
            digest = hashlib.sha256((self.bundle / filename).read_bytes()).hexdigest()
            checksums.append(f"{digest}  {filename}\n")
        (self.bundle / "SHA256SUMS").write_text("".join(checksums), encoding="ascii")

    def test_valid_bundle(self):
        verify_backup.verify(self.bundle)

    def test_changed_database_is_rejected(self):
        (self.bundle / "database.sql.gz").write_bytes(b"modified database")
        with self.assertRaisesRegex(ValueError, "Checksum mismatch"):
            verify_backup.verify(self.bundle)

    def test_path_traversal_is_rejected(self):
        self.write_archive("../../escape")
        with self.assertRaisesRegex(ValueError, "Unsafe attachment"):
            verify_backup.verify(self.bundle)

    def test_absolute_path_is_rejected(self):
        self.write_archive("/absolute/escape")
        with self.assertRaisesRegex(ValueError, "Unsafe attachment"):
            verify_backup.verify(self.bundle)

    def test_symbolic_link_is_rejected(self):
        self.write_archive("./private/linked", tarfile.SYMTYPE)
        with self.assertRaisesRegex(ValueError, "Unsafe attachment"):
            verify_backup.verify(self.bundle)

    def test_duplicate_checksum_is_rejected(self):
        checksum_file = self.bundle / "SHA256SUMS"
        checksum_file.write_text(checksum_file.read_text() + checksum_file.read_text().splitlines()[0] + "\n")
        with self.assertRaisesRegex(ValueError, "duplicate checksum"):
            verify_backup.verify(self.bundle)


if __name__ == "__main__":
    unittest.main()
