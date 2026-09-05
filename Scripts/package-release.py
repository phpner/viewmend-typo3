#!/usr/bin/env python3
"""Build an installable archive and verify its metadata and file inventory."""

import hashlib
import json
import re
import subprocess
import zipfile
import xml.etree.ElementTree as ET
from pathlib import Path, PurePosixPath


ROOT = Path(__file__).resolve().parent.parent
ROOT_FILES = {
    "composer.json", "ext_emconf.php", "ext_localconf.php", "ext_tables.sql",
    "README.md", "CHANGELOG.md", "LICENSE", "SECURITY.md", "CONTRIBUTING.md",
}
DIRECTORIES = {
    "Classes": {".php"},
    "Configuration": {".php", ".yaml"},
    "Resources": {".html", ".xlf", ".svg", ".css", ".js"},
    "Documentation": {".rst", ".txt", ".md", ".xml"},
}


def permitted(name):
    path = PurePosixPath(name)
    if path.is_absolute() or "\\" in name or str(path) != name:
        return False
    if any(part.startswith(".") for part in path.parts):
        return False
    return name in ROOT_FILES or (
        len(path.parts) > 1 and path.suffix in DIRECTORIES.get(path.parts[0], set())
    )


def main():
    metadata = json.loads((ROOT / "composer.json").read_text())
    version = metadata["extra"]["typo3/cms"]["version"]
    if not re.fullmatch(r"\d+\.\d+\.\d+", version):
        raise ValueError("Expected a stable semantic version")
    manifest = (ROOT / "ext_emconf.php").read_text()
    if not re.search(r"'version'\s*=>\s*'" + re.escape(version) + "'", manifest):
        raise ValueError("Composer and extension versions differ")
    if f"## [{version}]" not in (ROOT / "CHANGELOG.md").read_text():
        raise ValueError("Release notes are missing")
    project = ET.parse(ROOT / "Documentation/guides.xml").find("{https://www.phpdoc.org/guides}project")
    if project is None or project.get("release") != version:
        raise ValueError("Documentation and extension versions differ")
    destination = ROOT / "build/release"
    destination.mkdir(parents=True, exist_ok=True)
    name = "viewmend_site_tracker-" + version
    subprocess.run([
        "composer", "archive", "--format=zip", "--dir=" + str(destination),
        "--file=" + name, "--no-interaction",
    ], cwd=ROOT, check=True)
    package = destination / (name + ".zip")
    with zipfile.ZipFile(package) as archive:
        files = [item for item in archive.infolist() if not item.is_dir()]
        names = {item.filename for item in files}
        if len(names) != len(files) or not ROOT_FILES <= names:
            raise ValueError("Missing or duplicate package files")
        for item in files:
            if not permitted(item.filename) or item.file_size > 2 * 1024 * 1024:
                raise ValueError("Unexpected package member: " + item.filename)
            original = ROOT / item.filename
            content = archive.read(item)
            if original.is_symlink() or content != original.read_bytes():
                raise ValueError("Package member differs from source: " + item.filename)
            content.decode("utf-8")
        packaged = json.loads(archive.read("composer.json"))
        if packaged != metadata:
            raise ValueError("The archive contains different Composer metadata")
        paths = set(names)
        for name in names:
            paths.update(str(parent) for parent in PurePosixPath(name).parents if str(parent) != ".")
        attributes = subprocess.run([
            "git", "check-attr", "-z", "--stdin", "export-ignore",
        ], cwd=ROOT, input="\0".join(sorted(paths)).encode() + b"\0", check=True, capture_output=True)
        fields = attributes.stdout.decode().rstrip("\0").split("\0")
        for index in range(0, len(fields), 3):
            if fields[index + 2] == "set":
                raise ValueError("Git would exclude a package member: " + fields[index])
    digest = hashlib.sha256(package.read_bytes()).hexdigest()
    package.with_suffix(".zip.sha256").write_text(digest + "  " + package.name + "\n")
    print(f"Verified {package.name}: {len(files)} files, SHA-256 {digest}")


if __name__ == "__main__":
    main()
