#!/usr/bin/env python3
"""Build a complete source + optional target-specific dependency installer archive."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("icct_setup", ROOT / "installation/cli/setup.py")
setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(setup)


def source_files(root):
    """Package code, never a running installation's credentials, uploads or runtime state."""
    files = []
    for path in sorted(root.rglob("*")):
        relative = path.relative_to(root)
        if path.is_symlink():
            raise ValueError("Refusing source symlink: " + str(relative))
        if not path.is_file():
            continue
        if any(
            part in (".git", "__pycache__", "node_modules", ".DS_Store", "tests")
            for part in relative.parts
        ):
            continue
        if path.name.startswith("test_") or path.name.endswith("_test.php"):
            continue
        if (
            path.name.startswith(("._", ".env"))
            or path.name in ("config.local.json", "ssh.config.local.json")
            or path.suffix in (".pyc", ".log", ".pem", ".key")
        ):
            continue
        if relative.parts[:2] == ("installation", "bundles"):
            continue
        private = [
            "protocols/snmp/mibs/storage",
            "protocols/snmp/mibs/queries",
            "shared/assets/images/device-types/uploads",
        ]
        if any(str(relative).startswith(prefix + "/") for prefix in private) and path.name not in (
            ".htaccess",
            ".gitignore",
        ):
            continue
        files.append((path, relative.as_posix()))
    return files


def build_archive(root, output, bundle=None, native_bundles=None, component_bundles=None, media=None):
    setup.check_python_runtime()
    root, output = Path(root).resolve(), Path(output).resolve()
    if root in output.parents:
        raise ValueError("Release archives must be written outside the plugin directory.")
    if output.exists():
        raise ValueError("Refusing to replace an existing release archive.")
    files = source_files(root)
    if not (root / "setup.php").is_file() or not (root / "installation/cli/deploy.py").is_file():
        raise ValueError("Incomplete plugin source.")
    if media:
        if bundle or native_bundles or component_bundles:
            raise ValueError("--media supplies the base, component and native bundles together.")
        media = Path(media).resolve()
        bundle = media / "packages"
        target = json.loads((bundle / "bundle.json").read_text())["target"]
        component_bundles = [
            media / "components" / component
            for component in setup.MANIFEST["optional_components"]
            if setup.component_packages(target, component)
        ]
        native_bundles = [media / "native" / name for name in ("guacamole-strict", "pathchar")]
    packages, target = [], None
    if bundle:
        bundle = Path(bundle).resolve()
        target = json.loads((bundle / "bundle.json").read_text())["target"]
        packages = setup.verify_bundle(bundle, target)
    component_files, package_components = [], []
    for directory in component_bundles or []:
        directory = Path(directory).resolve()
        component_target = json.loads((directory / "bundle.json").read_text())["target"]
        if target is None or component_target != target:
            raise ValueError("Component bundles require a base bundle for the same OS and CPU.")
        component = setup.verify_component_bundle(directory, target)
        if component in package_components:
            raise ValueError("Duplicate package component.")
        package_components.append(component)
        for path in setup.verify_bundle(directory, target, component) + [directory / "bundle.json"]:
            component_files.append((path, "components/" + component + "/" + path.name))
    native_files, native_components = [], []
    if native_bundles:
        native_spec = importlib.util.spec_from_file_location(
            "icct_package_native", root / "installation/cli/native.py"
        )
        native = importlib.util.module_from_spec(native_spec)
        native_spec.loader.exec_module(native)
        for directory in native_bundles:
            directory = Path(directory).resolve()
            native_target = json.loads((directory / "native.json").read_text())["target"]
            if target and native_target != target:
                raise ValueError("Native and OS package bundles target different systems.")
            data = native.verify_bundle(directory, native_target)
            component = data["component"]
            if component in native_components:
                raise ValueError("Duplicate native component.")
            native_components.append(component)
            for name in ["native.json"] + ["payload/" + name for name in data["files"]]:
                native_files.append((directory / name, "native/" + component + "/" + name))
    manifest = {
        "format": 1,
        "target": target,
        "native_components": native_components,
        "package_components": package_components,
        "complete_media": bool(media),
        "files": {},
    }
    output.parent.mkdir(parents=True, exist_ok=True)
    try:
        with zipfile.ZipFile(output, "x", compression=zipfile.ZIP_DEFLATED) as archive:
            for path, name in files:
                name = "icct_nms/" + name
                archive.write(path, name)
                manifest["files"][name] = hashlib.sha256(path.read_bytes()).hexdigest()
            bootstrap = (root / "installation/cli/deploy.py").read_bytes()
            archive.writestr("install.py", bootstrap)
            manifest["files"]["install.py"] = hashlib.sha256(bootstrap).hexdigest()
            for path in packages + ([bundle / "bundle.json"] if bundle else []):
                name = "packages/" + path.name
                archive.write(path, name)
                manifest["files"][name] = hashlib.sha256(path.read_bytes()).hexdigest()
            for path, name in native_files + component_files:
                archive.write(path, name)
                manifest["files"][name] = hashlib.sha256(path.read_bytes()).hexdigest()
            archive.writestr("release.json", json.dumps(manifest, indent=2) + "\n")
            archive.writestr(
                "INSTALL.txt",
                "Extract outside the web root.\nRead-only verification: python3 install.py --cacti-root /path/to/cacti --plan\nInstall: sudo python3 install.py --cacti-root /path/to/cacti\nSource-only archives require --online or --no-packages.\nPackages stay outside the web root. Existing local configuration and uploads are retained.\nSHA-256 hashes detect transport changes; obtain this archive from your trusted build system.\n",
            )
    except BaseException:
        output.unlink(missing_ok=True)
        raise
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True)
    parser.add_argument("--bundle")
    parser.add_argument("--media", help="Build a complete installer from packages/, components/ and native/")
    parser.add_argument("--component-bundle", action="append", default=[])
    parser.add_argument("--native-bundle", action="append", default=[])
    args = parser.parse_args()
    result = build_archive(
        ROOT, args.output, args.bundle, args.native_bundle, args.component_bundle, media=args.media
    )
    print(
        json.dumps(
            {
                "archive": str(Path(args.output).resolve()),
                "files": len(result["files"]),
                "target": result["target"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
