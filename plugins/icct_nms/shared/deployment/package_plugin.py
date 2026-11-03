#!/usr/bin/env python3
"""Build an offline source ZIP with verified file hashes and no installation secrets/state."""
import argparse
import hashlib
import json
from pathlib import Path
import stat
import zipfile

PLUGIN = Path(__file__).resolve().parents[2]
PRIVATE = (
    "installation/bundles/",
    "protocols/snmp/mibs/storage/",
    "protocols/snmp/mibs/queries/",
    "shared/assets/images/device-types/uploads/",
)
RETIRED = [
    "inventory/diagnostics/services/backend/diagnostics_redis.php",
    "shared/tests/diagnostics_redis_test.php",
    "shared/tests/fault_alarm_navigation_test.cjs",
    "shared/deployment/redis-local.conf",
    "shared/deployment/redis-systemd.conf",
]


def source_files():
    for path in sorted(PLUGIN.rglob("*")):
        if not path.is_file() or path.is_symlink():
            continue
        relative = path.relative_to(PLUGIN).as_posix()
        if any(
            part in ("__pycache__", ".git", "node_modules") or part.startswith("._")
            for part in path.relative_to(PLUGIN).parts
        ):
            continue
        if path.name in (
            ".DS_Store",
            "ssh.config.local.json",
            "config.local.json",
            ".env",
        ) or path.name.startswith(".env."):
            continue
        if path.suffix in (".pyc", ".pyo", ".log", ".pem", ".key"):
            continue
        if relative.startswith(PRIVATE) and path.name not in (
            ".htaccess",
            ".gitignore",
            ".gitkeep",
        ):
            continue
        yield path, relative


def build(destination):
    destination = Path(destination).resolve()
    if PLUGIN in destination.parents:
        raise ValueError("Write release archives outside the plugin source tree.")
    files = list(source_files())
    contents = {name: path.read_bytes() for path, name in files}
    manifest = {
        "format": 1,
        "version": "1.4.1",
        "files": {name: hashlib.sha256(data).hexdigest() for name, data in contents.items()},
        "retired": RETIRED,
    }
    destination.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(destination, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for path, name in files:
            entry = zipfile.ZipInfo("plugins/icct_nms/" + name, date_time=(1980, 1, 1, 0, 0, 0))
            mode = 0o755 if path.stat().st_mode & stat.S_IXUSR else 0o644
            entry.external_attr = (stat.S_IFREG | mode) << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(entry, contents[name])
        archive.writestr("manifest.json", json.dumps(manifest, indent=4) + "\n")
    # Reopen and verify the actual deliverable, rather than just the source inputs.
    with zipfile.ZipFile(destination) as archive:
        for name, expected in manifest["files"].items():
            if hashlib.sha256(archive.read("plugins/icct_nms/" + name)).hexdigest() != expected:
                raise ValueError("Archive verification failed: " + name)
    digest = hashlib.sha256(destination.read_bytes()).hexdigest()
    destination.with_suffix(destination.suffix + ".sha256").write_text(
        digest + "  " + destination.name + "\n"
    )
    print(json.dumps({"archive": str(destination), "files": len(files), "sha256": digest}))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("destination")
    build(parser.parse_args().destination)
