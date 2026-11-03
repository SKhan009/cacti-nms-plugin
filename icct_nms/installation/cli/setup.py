#!/usr/bin/env python3
"""Portable ICCT dependency setup. Package/service mutations require an explicit install command."""
import argparse
import datetime
import grp
import hashlib
import json
import os
from pathlib import Path
import platform
import pwd
import re
import shutil
import subprocess
import sys
import tempfile

PLUGIN = Path(__file__).resolve().parents[2]
MANIFEST = json.loads((PLUGIN / "installation/dependencies.json").read_text())


def run(args, **kwargs):
    print("+ " + " ".join(map(str, args)), flush=True)
    return subprocess.run(list(map(str, args)), check=True, **kwargs)


def os_info():
    values = {}
    for line in Path("/etc/os-release").read_text().splitlines():
        if "=" in line:
            key, value = line.split("=", 1)
            values[key] = value.strip('"')
    names = (values.get("ID", "") + " " + values.get("ID_LIKE", "")).split()
    if any(name in names for name in ["rhel", "fedora", "centos", "rocky", "almalinux"]):
        family = "rhel"
    elif any(name in names for name in ["debian", "ubuntu"]):
        family = "debian"
    else:
        raise ValueError(
            "Unsupported OS. Use the check page and provision dependencies manually on this OS."
        )
    return dict(
        family=family, id=values["ID"], version=values["VERSION_ID"], arch=platform.machine()
    )


def validate_root(value):
    root = Path(value).resolve()
    if not (root / "include/cli_check.php").is_file() or root / "plugins/icct_nms" != PLUGIN:
        raise ValueError(
            "Run the installer shipped inside the target Cacti plugins/icct_nms directory."
        )
    # systemd substitution and existing adapters require simple absolute paths.
    if not re.fullmatch(r"/[A-Za-z0-9_./-]+", str(root)):
        raise ValueError("Cacti path must use letters, digits, slash, dot, underscore or hyphen.")
    return root


def component_packages(target, component=None):
    if component is None:
        return MANIFEST['packages'][target['family']]
    packages = MANIFEST['optional_components'][component]['packages'][target['family']]
    if not packages:
        raise ValueError('No supported repository package for this component on this OS; use the approved native build guide.')
    return packages


def verify_bundle(directory, target, component=None):
    directory = Path(directory).resolve()
    data = json.loads((directory / "bundle.json").read_text())
    if data.get("format") != 1 or data.get("target") != target:
        raise ValueError("Offline bundle OS version or CPU architecture differs from this server.")
    if data.get("packages") != component_packages(target, component) or data.get("component") != component:
        raise ValueError("Bundle package list does not match this plugin release; rebuild it.")
    files = []
    for name, expected in data.get("files", {}).items():
        if Path(name).name != name or not name.endswith((".rpm", ".deb")):
            raise ValueError("Invalid bundle package path.")
        path = directory / name
        if (
            path.is_symlink()
            or not path.is_file()
            or hashlib.sha256(path.read_bytes()).hexdigest() != expected
        ):
            raise ValueError("Missing or changed offline package: " + name)
        files.append(path)
    if not files:
        raise ValueError("Offline bundle has no packages.")
    return sorted(files)


def verify_component_bundle(directory, target):
    component = json.loads((Path(directory) / 'bundle.json').read_text()).get('component')
    if component not in MANIFEST['optional_components']:
        raise ValueError('Unknown component bundle.')
    verify_bundle(directory, target, component)
    return component


def download_bundle(directory, target, component=None):
    dest = Path(directory).resolve()
    if dest.exists() and any(dest.iterdir()):
        raise ValueError("Use an empty bundle directory; never mix OS versions or architectures.")
    dest.mkdir(parents=True, exist_ok=True)
    packages = component_packages(target, component)
    if target["family"] == "rhel":
        # dnf-plugins-core provides download; --alldeps includes already installed dependencies.
        run(["dnf", "download", "--resolve", "--alldeps", "--destdir", dest, *packages])
    else:
        # Empty dpkg status makes apt include dependencies already installed on the build machine.
        cache = dest / "cache"
        (cache / "partial").mkdir(parents=True)
        status = dest / "empty-status"
        status.touch()
        run(
            [
                "apt-get",
                "-y",
                "--download-only",
                "-o",
                "Dir::State::status=" + str(status),
                "-o",
                "Dir::Cache::archives=" + str(cache),
                "install",
                *packages,
            ]
        )
        for package in cache.glob("*.deb"):
            shutil.move(str(package), dest / package.name)
        shutil.rmtree(cache)
        status.unlink()
    suffix = "*.rpm" if target["family"] == "rhel" else "*.deb"
    files = {
        path.name: hashlib.sha256(path.read_bytes()).hexdigest()
        for path in sorted(dest.glob(suffix))
    }
    if not files:
        raise ValueError("Package manager produced no packages.")
    (dest / "bundle.json").write_text(
        json.dumps(dict(format=1, target=target, component=component, packages=packages, files=files), indent=2) + "\n"
    )
    print("Bundle built for exactly: " + json.dumps(target))


def install_packages(target, bundle, online=False, component=None):
    if bundle and online:
        raise ValueError("--online cannot be combined with --bundle.")
    if bundle:
        files = verify_bundle(bundle, target, component) if component else verify_bundle(bundle, target)
        if target["family"] == "rhel":
            run(["dnf", "-y", "--disablerepo=*", "install", *files])
        else:
            # APT 2.6's --no-download breaks local-DEB dependency installation.
            # Empty sources AND lists leave only installed packages and verified local DEBs.
            with tempfile.TemporaryDirectory(prefix='icct-offline-apt-') as temporary:
                location = Path(temporary)
                os.chmod(location, 0o755)
                (location / 'sources.list').touch()
                (location / 'sources.list.d').mkdir()
                (location / 'lists').mkdir()
                run(['apt-get', '-y', '--no-remove',
                     '-o', 'Dir::Etc::sourcelist=' + str(location / 'sources.list'),
                     '-o', 'Dir::Etc::sourceparts=' + str(location / 'sources.list.d'),
                     '-o', 'Dir::State::lists=' + str(location / 'lists'),
                     '-o', 'Dir::Cache::pkgcache=', '-o', 'Dir::Cache::srcpkgcache=',
                     'install', *files], env=dict(os.environ, DEBIAN_FRONTEND='noninteractive'))
    elif online:
        if target["family"] == "rhel":
            run(["dnf", "-y", "install", *component_packages(target, component)])
        else:
            run(["apt-get", "update"])
            run(
                ["apt-get", "-y", "--no-remove", "install", *component_packages(target, component)],
                env=dict(os.environ, DEBIAN_FRONTEND="noninteractive"),
            )
    else:
        raise ValueError(
            "Offline install requires --bundle, or use --no-packages after provisioning dependencies from approved media. Use --online explicitly for configured repositories."
        )


def write_unit(name, content):
    destination = Path("/etc/systemd/system") / name
    if destination.is_symlink():
        raise ValueError("Refusing service symlink: " + str(destination))
    changed = not destination.exists() or destination.read_text() != content
    if changed and destination.exists():
        backup = Path("/var/backups/icct-nms-install") / datetime.datetime.now().strftime(
            "%Y%m%dT%H%M%S%f"
        )
        backup.mkdir(parents=True, exist_ok=True)
        shutil.copy2(destination, backup / name)
    if changed:
        destination.write_text(content)
        destination.chmod(0o644)
    return changed


def preflight(root):
    php = shutil.which("php")
    if not php:
        raise ValueError("Install Cacti and its PHP runtime before running plugin setup.")
    code = 'try {require $argv[1]."/include/cli_check.php"; $v=(string)db_fetch_cell("SELECT cacti FROM version LIMIT 1"); if(version_compare(PHP_VERSION,"8.0.0","<") || version_compare($v,"1.2.31","<")) throw new RuntimeException("Complete Cacti 1.2.31+ installation with PHP 8.0+ before plugin setup.");} catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}'
    run([php, "-r", code, root])
    if not (root / "cli/plugin_manage.php").is_file():
        raise ValueError(
            "Native Cacti plugin_manage.php is missing; automatic lifecycle is unsupported."
        )
    return php


def services(root, user, group):
    php = shutil.which("php")
    if not php or not shutil.which("systemctl"):
        raise ValueError("PHP CLI and systemd are required for automatic service setup.")
    account = pwd.getpwnam(user)
    group_id = grp.getgrnam(group).gr_gid
    if account.pw_uid == 0:
        raise ValueError("Worker account must not be root.")
    for relative in ["protocols/snmp/mibs/storage", "shared/assets/images/device-types/uploads"]:
        path = PLUGIN / relative
        if path.is_symlink():
            raise ValueError("Refusing writable directory symlink.")
        path.mkdir(parents=True, exist_ok=True)
        os.chown(path, account.pw_uid, group_id)
        path.chmod(0o750)
    log = (root / "log").resolve()
    state = Path("/var/lib/icct-nms/netflow")
    state.mkdir(parents=True, exist_ok=True)
    os.chown(state, account.pw_uid, group_id)
    state.chmod(0o700)
    sources = {
        "icct-nms-diagnostics.service": "shared/deployment/icct-nms-diagnostics.service",
        "icct-nms-discovery.service": "inventory/discovery/deploy/icct-nms-discovery.service",
        "icct-nms-location.service": "inventory/discovery/deploy/icct-nms-location.service",
        "icct-nms-netflow.service": "protocols/netflow/rhel/icct-nms-netflow.service.in",
    }
    changed = {}
    for name, source in sources.items():
        content = (PLUGIN / source).read_text().replace("/var/www/html/cacti", str(root))
        content = content.replace("User=apache", "User=" + user).replace(
            "Group=apache", "Group=" + group
        )
        content = (
            content.replace("/usr/bin/php", php)
            .replace("@PLUGIN_ROOT@", str(PLUGIN))
            .replace("@CACTI_LOG@", str(log))
        )
        if "SupplementaryGroups=dialout" in content:
            try:
                grp.getgrnam("dialout")
            except KeyError:
                raise ValueError(
                    "The dialout group is missing. Provision serial-device permissions before installing GNSS services."
                )
        changed[name] = write_unit(name, content)
    run(["systemctl", "daemon-reload"])
    for name, updated in changed.items():
        run(["systemctl", "enable", "--now", name])
        if updated:
            run(["systemctl", "restart", name])
        run(["systemctl", "is-active", "--quiet", name])
    # Reuse the capture-tested receiver adapter; it preserves existing logging rules.
    env = dict(os.environ, WEB_USER=user, WEB_GROUP=group, PHP_BINARY=php)
    run(["bash", PLUGIN / "protocols/syslog/rhel/install_syslog_rhel.sh", root], env=env)
    if shutil.which("restorecon"):
        run(
            [
                "restorecon",
                "-R",
                PLUGIN / "installation",
                PLUGIN / "protocols/snmp/mibs/storage",
                PLUGIN / "shared/assets/images/device-types/uploads",
            ]
        )


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=["plan", "install", "bundle"])
    parser.add_argument("--cacti-root", default=str(PLUGIN.parents[1]))
    parser.add_argument(
        "--bundle", help="Offline bundle directory (input for install, output for bundle)"
    )
    parser.add_argument(
        "--web-user",
        help="Existing PHP/collector account; defaults apache on RHEL, www-data on Debian",
    )
    parser.add_argument("--web-group")
    parser.add_argument("--all-components", action="store_true", help="Install all available protocol dependency profiles")
    parser.add_argument("--component-bundle", action="append", default=[], help="Offline optional dependency bundle; may be repeated")
    parser.add_argument("--native-bundle", action="append", default=[], help="Verified native dependency bundle; may be repeated")
    parser.add_argument("--component", choices=list(MANIFEST["optional_components"]), help="Install or bundle one optional dependency only; no plugin or service configuration")
    parser.add_argument('--application-only', action='store_true', help='Install application pages/schema only; leave OS packages and services managed externally')
    parser.add_argument(
        "--online",
        action="store_true",
        help="Explicitly allow installing packages from configured repositories",
    )
    parser.add_argument(
        "--no-packages",
        action="store_true",
        help="Use packages already provisioned by the administrator",
    )
    args = parser.parse_args()
    if args.component and (args.native_bundle or args.component_bundle):
        parser.error('--component cannot be combined with additional bundles.')
    if args.application_only and (args.bundle or args.online or args.no_packages or args.native_bundle or args.component_bundle or args.all_components):
        parser.error('--application-only cannot be combined with package options.')
    if args.online and (args.bundle or args.no_packages):
        parser.error("--online cannot be combined with --bundle or --no-packages.")
    if args.all_components and (args.no_packages or args.component):
        parser.error('--all-components cannot be combined with --component or --no-packages.')
    if args.no_packages and args.component_bundle:
        parser.error('--no-packages cannot install component packages.')
    target = os_info()
    if args.action == 'bundle':
        if os.geteuid() != 0:
            raise ValueError('Run bundle as root on the matching build system.')
        if not args.bundle:
            raise ValueError('bundle requires --bundle OUTPUT_DIRECTORY')
        download_bundle(args.bundle, target, args.component) if args.component else download_bundle(args.bundle, target)
        return
    root = validate_root(args.cacti_root)
    user = args.web_user or ("apache" if target["family"] == "rhel" else "www-data")
    group = args.web_group or user
    if not re.fullmatch(r"[a-z_][a-z0-9_-]*", user) or not re.fullmatch(
        r"[a-z_][a-z0-9_-]*", group
    ):
        raise ValueError("Invalid service user/group.")
    component_bundles = {}
    for directory in args.component_bundle:
        component = verify_component_bundle(directory, target)
        if component in component_bundles:
            raise ValueError('Duplicate component bundle.')
        component_bundles[component] = directory
    selected_components = list(component_bundles)
    if args.all_components:
        selected_components = [name for name, spec in MANIFEST['optional_components'].items() if spec['packages'][target['family']]]
        if not args.online and set(selected_components) - set(component_bundles):
            raise ValueError('Offline --all-components requires bundles for: '+', '.join(sorted(set(selected_components)-set(component_bundles))))
    plan = dict(
        target=target,
        cacti=str(root),
        user=user,
        group=group,
        packages=component_packages(target, args.component),
        component=args.component,
        native_bundles=args.native_bundle,
        component_bundles=component_bundles,
        selected_components=selected_components,
        offline=not args.online,
        pending=MANIFEST["pending_features"],
        additional=[
            "Strict SSH/HTTPS setup",
            "Pathchar binary from an approved source",
            "Device credentials and protocol assignments",
            "Exporter/firewall configuration",
        ],
    )
    if args.action == "plan":
        for directory in args.native_bundle:
            subprocess.run([sys.executable, str(PLUGIN / 'installation/cli/native.py'), 'verify', '--bundle', directory], check=True, stdout=subprocess.DEVNULL)
        if args.bundle:
            verify_bundle(args.bundle, target, args.component)
        print(json.dumps(plan, indent=2))
        return
    if os.geteuid() != 0:
        raise ValueError("Run install/bundle as root; plan is read-only and needs no privileges.")
    if args.component:
        if args.application_only or args.no_packages:
            raise ValueError('--component requires --online or --bundle.')
        install_packages(target, args.bundle, args.online, args.component)
        print('Dependency installed. Application integration and service configuration are separate.')
        return
    for directory in args.native_bundle:
        run([sys.executable, PLUGIN / 'installation/cli/native.py', 'verify', '--bundle', directory])
    php = preflight(root)
    if not args.application_only and not args.no_packages:
        install_packages(target, args.bundle, args.online)
    elif args.bundle:
        raise ValueError("--no-packages cannot be combined with --bundle.")
    for component in selected_components:
        directory = component_bundles.get(component)
        install_packages(target, directory, online=directory is None and args.online, component=component)
    # Use native Cacti lifecycle APIs; never grant all user realms or create core devices.
    php = shutil.which("php")
    if not php:
        raise ValueError("PHP CLI is missing.")
    run([php, root / "cli/plugin_manage.php", "--plugin=icct_nms", "--install"])
    run([php, root / "cli/plugin_manage.php", "--plugin=icct_nms", "--enable"])
    code = 'try {require $argv[1]."/include/cli_check.php"; if ((int)db_fetch_cell("SELECT status FROM plugin_config WHERE directory=\'icct_nms\'") !== 1) throw new RuntimeException("Install/enable ICCT NMS in Cacti Plugin Management first.");} catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}'
    run([php, "-r", code, root])
    if args.application_only:
        print('Application pages/schema installed through native Cacti. OS packages and services were not changed.')
        return
    for directory in args.native_bundle:
        run([sys.executable, PLUGIN / 'installation/cli/native.py', 'install', '--bundle', directory, '--web-group', group])
    services(root, user, group)
    run([php, PLUGIN / "installation/cli/check.php", "--save"])
    print(
        "Selected packages and common services installed and checked. Open Settings > Installation Checks for feature readiness and remaining configuration."
    )


if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError) as error:
        print("Setup stopped: " + str(error), file=sys.stderr)
        sys.exit(1)
