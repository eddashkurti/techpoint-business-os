#!/usr/bin/env python3
"""Verify a recovered bundle before loading SQL or extracting attachments."""
import hashlib
import json
from pathlib import Path, PurePosixPath
import sys
import tarfile

EXPECTED = {"database.sql.gz", "attachments.tar.gz", "app_key", "table-counts.tsv", "manifest.json"}


def verify(bundle: Path) -> None:
    manifest = json.loads((bundle / "manifest.json").read_text(encoding="utf-8"))
    if manifest.get("format") != 1:
        raise ValueError("Unsupported backup bundle version")
    listed = set(manifest.get("files", [])) | {"manifest.json"}
    if listed != EXPECTED:
        raise ValueError("Unexpected backup files")
    checksums = {}
    for line in (bundle / "SHA256SUMS").read_text(encoding="ascii").splitlines():
        digest, name = line.split(maxsplit=1)
        name = name.lstrip(" *")
        if name not in EXPECTED or name in checksums:
            raise ValueError("Unexpected or duplicate checksum pathname")
        checksums[name] = digest
    if set(checksums) != EXPECTED:
        raise ValueError("Incomplete checksum list")
    for name, expected in checksums.items():
        path = bundle / name
        if not path.is_file() or path.is_symlink():
            raise ValueError(f"Missing regular bundle file: {name}")
        with path.open("rb") as stream:
            digest = hashlib.file_digest(stream, "sha256").hexdigest()
        if digest != expected:
            raise ValueError(f"Checksum mismatch: {name}")
    with tarfile.open(bundle / "attachments.tar.gz", "r:gz") as archive:
        for member in archive:
            path = PurePosixPath(member.name)
            if path.is_absolute() or ".." in path.parts or not (member.isfile() or member.isdir()):
                raise ValueError(f"Unsafe attachment archive member: {member.name}")


if __name__ == "__main__":
    try:
        verify(Path(sys.argv[1]).resolve(strict=True))
    except (IndexError, OSError, ValueError, tarfile.TarError) as error:
        print(f"Backup validation failed: {error}", file=sys.stderr)
        sys.exit(1)
    print("Recovered checksums and attachment paths verified.")
