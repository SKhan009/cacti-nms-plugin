#!/usr/bin/env python3
"""Release bootstrap: verify the archive, retain local data, then run bundled setup."""
import argparse
import datetime
import hashlib
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile

MEDIA_PATH = Path("icct_nms/installation/bundles/media")


def verify_release(directory):
    directory = Path(directory).resolve()
    manifest = json.loads((directory / "release.json").read_text())
    if (
        manifest.get("format") != 1
        or not isinstance(manifest.get("files"), dict)
        or not manifest["files"]
    ):
        raise ValueError("Invalid release manifest.")
    for name, digest in manifest["files"].items():
        parts = PurePosixPath(name).parts
        if (
            not parts
            or name.startswith("/")
            or ".." in parts
            or "\\" in name
            or parts[0] not in ("icct_nms", "packages", "native", "components", "install.py")
        ):
            raise ValueError("Invalid release path: " + name)
        path = directory / name
        if any(
            parent.is_symlink() for parent in [path, *path.parents] if parent != directory.parent
        ):
            raise ValueError("Refusing release symlink: " + name)
        if not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != digest:
            raise ValueError("Missing or changed release file: " + name)
    for required in [
        "install.py",
        "icct_nms/INFO",
        "icct_nms/setup.php",
        "icct_nms/installation/cli/setup.py",
    ]:
        if required not in manifest["files"]:
            raise ValueError("Release is incomplete: " + required)
    return manifest


def validate_destination(root, names):
    root = Path(root).resolve()
    if not (root / "include/cli_check.php").is_file():
        raise ValueError("Existing Cacti installation required.")
    destination = root / "plugins/icct_nms"
    for relative in names:
        if not relative.startswith("icct_nms/"):
            continue
        parts = PurePosixPath(relative).parts
        if ".." in parts or "\\" in relative:
            raise ValueError("Invalid plugin destination.")
        local = "/".join(parts[1:])
        if (
            parts[-1].startswith(".env")
            or parts[-1] in ("config.local.json", "ssh.config.local.json")
            or (
                any(
                    local.startswith(prefix + "/")
                    for prefix in (
                        "protocols/snmp/mibs/storage",
                        "protocols/snmp/mibs/queries",
                        "shared/assets/images/device-types/uploads",
                    )
                )
                and parts[-1] not in (".htaccess", ".gitignore")
            )
        ):
            raise ValueError("Release contains local configuration or runtime data: " + relative)
        path = root / "plugins" / relative
        if destination not in path.parents:
            raise ValueError("Invalid plugin destination.")
        for candidate in [path, *path.parents]:
            if candidate.is_symlink():
                raise ValueError("Refusing destination symlink: " + str(candidate))
            if candidate == root:
                break
    return destination


def check_release_version(directory, root):
    def version(path):
        if not path.is_file():
            return None
        match = re.search(
            r'^version\s*=\s*[\'"]?(\d+\.\d+\.\d+)',
            path.read_text(),
            re.MULTILINE | re.IGNORECASE,
        )
        if not match:
            raise ValueError("Cannot read plugin version from " + str(path))
        return tuple(int(part) for part in match.group(1).split("."))

    incoming = version(Path(directory) / "icct_nms/INFO")
    if incoming is None:
        raise ValueError("Release INFO is missing.")
    current = version(Path(root) / "plugins/icct_nms/INFO")
    if current and current > incoming:
        raise ValueError(
            "Refusing to downgrade an installed plugin; use the current release archive."
        )
    return ".".join(map(str, incoming))


def copy_source(directory, root, manifest, backup_root=Path("/var/backups/icct-nms-install")):
    destination = validate_destination(root, manifest["files"])
    backup = None
    if destination.exists():
        backup_dir = Path(backup_root)
        backup_dir.mkdir(parents=True, exist_ok=True)
        backup = backup_dir / (
            "plugin-" + datetime.datetime.now().strftime("%Y%m%dT%H%M%S%f") + ".tar.gz"
        )
        with tarfile.open(backup, "x:gz") as archive:
            archive.add(destination, arcname="icct_nms")
        backup.chmod(0o600)
    media = Path(root) / "plugins" / MEDIA_PATH
    if any(name.startswith(MEDIA_PATH.as_posix() + "/") for name in manifest["files"]):
        media.mkdir(mode=0o700, parents=True, exist_ok=True)
        media.chmod(0o700)
    for name in manifest["files"]:
        if not name.startswith("icct_nms/"):
            continue
        path = Path(root) / "plugins" / name
        path.parent.mkdir(parents=True, exist_ok=True)
        descriptor, temporary = tempfile.mkstemp(prefix=".icct-install-", dir=path.parent)
        try:
            with (
                os.fdopen(descriptor, "wb") as stream,
                open(Path(directory) / name, "rb") as source,
            ):
                shutil.copyfileobj(source, stream)
            os.chmod(temporary, 0o644)
            os.replace(temporary, path)
        finally:
            Path(temporary).unlink(missing_ok=True)
    return destination, backup


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--cacti-root", help="Existing Cacti root; detected automatically for standard installations.")
    parser.add_argument("--plan", action="store_true")
    parser.add_argument("--online", action="store_true")
    parser.add_argument("--offline", action="store_true", help="Require bundled packages; never use repositories.")
    parser.add_argument("--allow-partial-media", action="store_true")
    parser.add_argument("--no-packages", action="store_true")
    parser.add_argument("--application-only", action="store_true")
    parser.add_argument("--web-user")
    parser.add_argument("--web-group")
    parser.add_argument("--ssh-origin")
    parser.add_argument("--ssh-service-user", default="icct-nms-ssh")
    parser.add_argument("--ssh-state-directory", default="/var/lib/icct-nms-ssh")
    parser.add_argument("--ssh-guacd-port", type=int, default=4823)
    parser.add_argument("--trap-auth-file")
    parser.add_argument("--trap-listener")
    args = parser.parse_args()
    if args.offline and (args.online or args.no_packages or args.application_only):
        parser.error('--offline cannot be combined with online or source-only installation.')
    if args.application_only and (
        args.online or args.no_packages or args.ssh_origin or args.trap_auth_file or args.trap_listener
    ):
        parser.error("--application-only cannot be combined with package or service options.")
    directory = Path(__file__).resolve().parent
    manifest = verify_release(directory)
    if args.offline and not manifest.get('target'):
        raise ValueError('This source-only archive contains no offline packages.')
    if manifest.get('partial_media') and not args.application_only and not args.allow_partial_media:
        raise ValueError('This archive has incomplete media. Use a complete release or explicitly select --allow-partial-media.')
    if manifest.get('target') and args.no_packages:
        raise ValueError('A bundled release cannot use --no-packages; use the full installer.')
    prefix = MEDIA_PATH.as_posix() + "/"
    embedded = any(name.startswith(prefix) for name in manifest["files"])
    media = directory / MEDIA_PATH if embedded else directory
    if not embedded:
        prefix = ""
    spec = importlib.util.spec_from_file_location(
        "icct_release_setup", directory / "icct_nms/installation/cli/setup.py"
    )
    setup = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(setup)
    setup.check_python_runtime()
    root = setup.discover_cacti_root(args.cacti_root)
    if not re.fullmatch(r"/[A-Za-z0-9_./-]+", str(root)):
        raise ValueError("Unsupported Cacti path characters.")
    destination = validate_destination(root, manifest["files"])
    release_version = check_release_version(directory, root)
    target = setup.os_info()
    trap_auth = None
    if args.trap_auth_file or args.trap_listener:
        trap_auth = setup.validate_trap_settings(args.trap_auth_file, args.trap_listener, target)
    if args.application_only:
        pass
    elif manifest["target"]:
        if args.online:
            raise ValueError("A bundled release cannot use online package repositories.")
        setup.verify_bundle(media / "packages", target)
    elif args.online == args.no_packages:
        raise ValueError("Source-only release requires either --online or --no-packages.")
    package_components = manifest.get("package_components", [])
    if not args.application_only:
        for component in package_components:
            if (
                component not in setup.MANIFEST["optional_components"]
                or prefix + "components/" + component + "/bundle.json" not in manifest["files"]
            ):
                raise ValueError("Invalid package component in release.")
            observed = setup.verify_component_bundle(media / "components" / component, target)
            if observed != component:
                raise ValueError("Package component does not match release directory.")
    components = manifest.get("native_components", [])
    complete_media = manifest.get("complete_media", False)
    if complete_media and not args.application_only:
        expected = {
            name for name, spec in setup.MANIFEST["optional_components"].items()
            if spec["packages"][target["family"]]
        }
        if (not manifest["target"] or set(package_components) != expected
                or set(components) != {"guacamole-strict", "pathchar"}):
            raise ValueError("Complete media requires all package profiles and both native bundles.")
    if components and not args.application_only:
        native_spec = importlib.util.spec_from_file_location(
            "icct_release_native", directory / "icct_nms/installation/cli/native.py"
        )
        native = importlib.util.module_from_spec(native_spec)
        native_spec.loader.exec_module(native)
        for component in components:
            if (
                component not in native.PROFILES
                or prefix + "native/" + component + "/native.json" not in manifest["files"]
            ):
                raise ValueError("Invalid release native component.")
            data = native.verify_bundle(media / "native" / component, target)
            if data["component"] != component:
                raise ValueError("Native component does not match release directory.")
    plan = {
        "version": release_version,
        "destination": str(destination),
        "target": target,
        "offline": not args.online,
        "partial_media": manifest.get('partial_media', False),
        "package_components": package_components,
        "repository_components": setup.repository_components(target) if args.online else [],
        "native_components_not_bundled": [name for name, spec in setup.MANIFEST['optional_components'].items()
                                         if spec.get('native_binary') and name not in components],
        "native_components": components,
        "syslog_port_runner": target["family"] == "rhel" and not args.application_only,
        "offline_packages_runner": (complete_media or manifest.get('partial_media', False)) and target["family"] == "rhel" and not args.application_only,
        "trap_listener": args.trap_listener,
        "source_files": sum(name.startswith("icct_nms/") for name in manifest["files"]),
    }
    if args.ssh_origin:
        setup.validate_ssh_settings(
            args.ssh_origin,
            args.ssh_service_user,
            args.ssh_state_directory,
            args.ssh_guacd_port,
        )
    if args.ssh_origin and "guacamole-strict" not in manifest.get("native_components", []):
        raise ValueError(
            "SSH setup requires a release containing the strict Guacamole native bundle."
        )
    if args.ssh_origin and args.no_packages:
        raise ValueError("Use the full installer when provisioning SSH from the native bundle.")
    if args.plan:
        plan["ssh_origin"] = args.ssh_origin
        print(json.dumps(plan, indent=2))
        return
    if os.geteuid() != 0:
        raise ValueError("Run deployment as administrator; --plan is read-only.")
    setup.check_cacti_lifecycle(root)
    # A verified bundle may supply PHP itself. The target installer checks its runtime after DNF/APT.
    if args.application_only or args.no_packages:
        setup.preflight(root)
    destination, backup = copy_source(directory, root, manifest)
    if embedded:
        media = destination.parent / MEDIA_PATH
    if shutil.which("restorecon"):
        subprocess.run(["restorecon", "-R", str(destination)], check=True)
    command = [
        sys.executable,
        str(destination / "installation/cli/setup.py"),
        "install",
        "--cacti-root",
        str(root),
    ]
    if args.application_only:
        command += ["--application-only"]
    elif args.no_packages:
        command += ["--no-packages"]
    else:
        command += (
            ["--bundle", str(media / "packages")]
            if manifest["target"]
            else ["--online", "--all-components"]
        )
    if not args.application_only and not args.no_packages:
        for component in package_components:
            command += ["--component-bundle", str(media / "components" / component)]
        for component in manifest.get("native_components", []):
            if component not in ("guacamole-strict", "pathchar"):
                raise ValueError("Unsupported native component.")
            command += ["--native-bundle", str(media / "native" / component)]
    if args.ssh_origin:
        command += [
            "--ssh-origin",
            args.ssh_origin,
            "--ssh-service-user",
            args.ssh_service_user,
            "--ssh-state-directory",
            args.ssh_state_directory,
            "--ssh-guacd-port",
            str(args.ssh_guacd_port),
        ]
    if trap_auth:
        command += ["--trap-auth-file", str(trap_auth), "--trap-listener", args.trap_listener]
    for name in ["web_user", "web_group"]:
        if getattr(args, name):
            command.extend(["--" + name.replace("_", "-"), getattr(args, name)])
    print(
        json.dumps(
            {
                "source_installed": str(destination),
                "backup": str(backup) if backup else None,
            }
        ),
        flush=True,
    )
    environment = os.environ.copy()
    if plan["offline_packages_runner"]:
        environment["ICCT_DEFER_RUNNER_TO_FULL_INSTALLER"] = "1"
    subprocess.run(command, check=True, env=environment)



if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError) as error:
        print("Installation stopped: " + str(error), file=sys.stderr)
        sys.exit(1)
