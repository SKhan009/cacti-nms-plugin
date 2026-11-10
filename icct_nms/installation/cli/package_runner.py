#!/usr/bin/env python3
"""Run fixed, offline RPM operations requested by the local Cacti administrator."""

import argparse
import fcntl
import grp
import json
import logging
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time

from setup import (
    MANIFEST, check_python_runtime, component_packages, configure_selinux_writable,
    os_info, run, validate_root, verify_bundle, write_unit,
)
import native

STATE = Path("/var/lib/icct-nms-package-runner")
UNIT = "icct-nms-package-runner"
LOGGER = logging.getLogger(UNIT)
REMOVAL_IMPACTS = {
    "traceroute": "Traceroute UDP, ICMP and TCP tools",
    "mtr": "On-demand and scheduled MTR diagnostics",
    "hping3": "Hping packet diagnostics",
    "iperf3": "iPerf bandwidth tests",
    "netperf": "Netperf throughput tests",
    "redis": "Redis cache and messaging services that use this RPM",
    "python3-websockets": "Python applications that use the WebSocket library",
    "chrony": "Chrony clock synchronization and local NTP statistics",
    "tacacs": "The local TACACS+ server supplied by this RPM",
    "guacd": "The distribution Guacamole daemon supplied by this RPM",
    "icct-nms-pathchar": "Pathchar path capacity diagnostics",
    "icct-nms-guacamole-strict": "The strict-auth SSH daemon",
    "lftp": "FTP and LFTP client commands",
    "tftp": "TFTP client commands",
    "openssh-clients": "OpenSSH ssh, scp and sftp client commands",
    "telnet": "Telnet client commands",
}
NATIVE_EXECUTABLES = {"pathchar": "sbin/pchar", "guacamole-strict": "sbin/guacd"}
NATIVE_ALTERNATIVES = {
    "pathchar": ["/usr/local/sbin/pchar", "/usr/local/bin/pchar", "/usr/bin/pchar"],
    "guacamole-strict": ["/opt/nms-guacamole/1.6.0/sbin/guacd"],
}


def write_json(path, data, mode=0o600):
    with tempfile.NamedTemporaryFile(dir=path.parent, delete=False, mode="w") as stream:
        staged = Path(stream.name)
        try:
            os.fchmod(stream.fileno(), mode)
            json.dump(data, stream)
            stream.flush()
            os.fsync(stream.fileno())
            staged.replace(path)
        finally:
            staged.unlink(missing_ok=True)


def profiles(target):
    return {"base": component_packages(target), **{
        name: component_packages(target, name)
        for name in MANIFEST["optional_components"]
    }}


def bundle_path(media, profile):
    return media / ("packages" if profile == "base" else "components/" + profile)


def verified_media(media, target, profile):
    return verify_bundle(bundle_path(media, profile), target, None if profile == "base" else profile)


def native_media(media, target, component):
    data = native.verify_bundle(media / "native" / component, target)
    if data["component"] != component:
        raise ValueError("Native bundle does not match its directory.")
    return data


def external_installations(target, installed):
    result = {}
    for component, executable in NATIVE_EXECUTABLES.items():
        packages = component_packages(target, component)
        prefix = Path(native.PROFILES[component]["prefix"])
        paths = [prefix / executable, *map(Path, NATIVE_ALTERNATIVES[component])]
        if packages and packages[0] not in installed and any(path.is_file() for path in paths):
            result[packages[0]] = "Installed outside RPM"
    return result


def provision(args, target):
    root = validate_root(args.cacti_root)
    account = pwd.getpwnam(args.web_user)
    uid = account.pw_uid
    gid = grp.getgrnam(args.web_group).gr_gid if args.web_group else account.pw_gid
    if uid == 0:
        raise ValueError("Use the existing unprivileged PHP service account.")
    source = Path(args.media).resolve(strict=True)

    media = Path(tempfile.mkdtemp(prefix="media-", dir=STATE))
    try:
        for profile in profiles(target):
            print("Checking offline profile " + profile, flush=True)
            files = verified_media(source, target, profile)
            destination = bundle_path(media, profile)
            destination.mkdir(parents=True)
            shutil.copyfile(bundle_path(source, profile) / "bundle.json", destination / "bundle.json")
            for file in files:
                shutil.copyfile(file, destination / file.name)
            # Verify the private copy before it can become a package source.
            verified_media(media, target, profile)
        for component in NATIVE_EXECUTABLES:
            original = source / "native" / component
            if original.exists():
                data = native_media(source, target, component)
                destination = media / "native" / component
                for name in ["native.json", *("payload/" + name for name in data["files"])]:
                    output = destination / name
                    output.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copyfile(original / name, output)
                native_media(media, target, component)
        prerequisites = []
        if not shutil.which("setfacl"):
            prerequisites.append("acl")
        if shutil.which("getenforce") and not shutil.which("semanage"):
            prerequisites.append("policycoreutils-python-utils")
        if prerequisites:
            files = verified_media(media, target, "base")
            apply_transaction("install", prerequisites, files, target, lambda *lines: print(*lines))

        request_dir = STATE / "requests"
        request_dir.mkdir(exist_ok=True)
        os.chown(request_dir, 0, 0)
        request_dir.chmod(0o730)
        # Limit submission to the PHP account even when it shares a group.
        run(["/usr/bin/setfacl", "-m", "u:" + args.web_user + ":rwx,g::---", request_dir])
        configure_selinux_writable([request_dir])
        (STATE / "public").mkdir(exist_ok=True, mode=0o755)
        program = STATE / "runner/installation"
        (program / "cli").mkdir(parents=True, exist_ok=True)
        for file in [Path(__file__), Path(__file__).with_name("setup.py"), Path(__file__).with_name("native.py"),
                     Path(__file__).parents[1] / "dependencies.json"]:
            destination = program / ("cli" if file.suffix == ".py" else "") / file.name
            shutil.copyfile(file, destination)
        for component in NATIVE_EXECUTABLES:
            patch = Path(native.PROFILES[component]["patch"])
            destination = program.parent / patch
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(native.ROOT / patch, destination)
        write_unit(UNIT + ".service", "[Unit]\nDescription=ICCT NMS offline package runner\n"
                   "[Service]\nType=oneshot\nUMask=0022\nTimeoutStartSec=15min\nPrivateNetwork=yes\n"
                   f"ExecStart=/usr/bin/python3 {program}/cli/package_runner.py work\n")
        write_unit(UNIT + ".timer", "[Unit]\nDescription=Check for ICCT NMS package jobs\n"
                   "[Timer]\nOnBootSec=3s\nOnUnitInactiveSec=4s\nAccuracySec=1s\n"
                   "[Install]\nWantedBy=timers.target\n")
        write_json(STATE / "config.json", {"root": str(root), "uid": uid, "gid": gid, "media": str(media)})
    except BaseException:
        shutil.rmtree(media)
        raise

    for old in STATE.glob("media-*"):
        if old != media:
            shutil.rmtree(old)
    run(["/usr/bin/systemctl", "daemon-reload"])
    run(["/usr/bin/systemctl", "enable", "--now", UNIT + ".timer"])
    run(["/usr/bin/systemctl", "start", "--no-block", UNIT + ".service"])


def installed_packages(names):
    result = subprocess.run(
        ["/usr/bin/rpm", "-qa", "--qf", "%{NAME}\t%{EPOCHNUM}:%{VERSION}-%{RELEASE}.%{ARCH}:%{INSTALLTID}\\n"],
        check=True, capture_output=True, text=True,
    )
    installed = {}
    for line in result.stdout.splitlines():
        name, version = line.split("\t", 1)
        if name in names:
            installed.setdefault(name, []).append(version)
    return {name: sorted(versions) for name, versions in installed.items()}


def package_versions(media, target, grouped, installed):
    from functools import cmp_to_key

    import rpm

    def compare(left, right):
        return rpm.labelCompare(left[:3], right[:3])

    def display(version):
        epoch, value, release, arch = version
        prefix = epoch + ":" if epoch != "0" else ""
        return f"{prefix}{value}-{release}.{arch}"

    arches = {target["arch"], "noarch"}
    current = {}
    for name, versions in installed.items():
        current[name] = []
        for receipt in versions:
            nevra, _ = receipt.rsplit(":", 1)
            epoch, version_release_arch = nevra.split(":", 1)
            version_release, arch = version_release_arch.rsplit(".", 1)
            version, release = version_release.rsplit("-", 1)
            current[name].append((epoch, version, release, arch))

    result = {}
    for profile, names in grouped.items():
        candidates = {}
        readable = True
        directory = bundle_path(media, profile)
        try:
            manifest = json.loads((directory / "bundle.json").read_text())
            if (manifest["format"] != 1 or manifest["target"] != target or manifest["packages"] != names
                    or manifest["component"] != (None if profile == "base" else profile)):
                raise ValueError("Media does not match this profile.")
            files = [
                directory / filename for filename in manifest["files"]
                if Path(filename).name == filename and filename.endswith(".rpm")
                and filename[:-4].rsplit("-", 2)[0] in names
                and (directory / filename).is_file() and not (directory / filename).is_symlink()
            ]
            if files:
                headers = subprocess.run(
                    ["/usr/bin/rpm", "-qp", "--qf", "%{NAME}\\t%{EPOCHNUM}\\t%{VERSION}\\t%{RELEASE}\\t%{ARCH}\\n",
                     *files],
                    check=True, capture_output=True, text=True,
                ).stdout
                for line in headers.splitlines():
                    name, *version = line.split("\t")
                    if name in names and len(version) == 4 and version[3] in arches:
                        candidates.setdefault(name, []).append(tuple(version))
        except (OSError, KeyError, TypeError, ValueError, subprocess.CalledProcessError) as error:
            readable = False
            LOGGER.warning("Cannot read offline versions for %s: %s", profile, error)
        result[profile] = {}
        for name in names:
            versions = current.get(name, [])
            compatible = [version for version in versions if version[3] in arches]
            bundled = candidates.get(name, [])
            status = "No bundled RPM" if readable else "Bundle version unavailable"
            if len(bundled) > 1:
                status = "Multiple bundled RPMs"
            elif bundled and not versions:
                status = "Missing"
            elif bundled and not compatible:
                status = "Architecture differs"
            elif bundled:
                difference = compare(max(compatible, key=cmp_to_key(compare)), bundled[0])
                status = "Matches bundle" if difference == 0 else (
                    "Bundle is newer" if difference < 0 else "Installed is newer"
                )
            result[profile][name] = {
                "installed": sorted({display(version) for version in versions}),
                "bundled": sorted({display(version) for version in bundled}),
                "status": status,
            }
    return result


def package_scope(target):
    grouped = profiles(target)
    tools = {name: profile for profile, names in reversed(list(grouped.items())) for name in names}
    protected = set(grouped["base"]) - set(MANIFEST["rhel_removable_tools"])
    return grouped, tools, protected


def read_request(path, uid):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as stream:
        info = os.fstat(stream.fileno())
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_size > 512
                or not 0 <= time.time() - info.st_mtime < 90):
            raise ValueError("Invalid or expired package request.")
        job = json.loads(stream.read(513))
    if (not isinstance(job, dict) or not re.fullmatch(r"[a-f0-9]{32}", job.get("id", ""))
            or job.get("action") not in ("install", "uninstall", "preview-uninstall", "verify")
            or not isinstance(job.get("target"), str)):
        raise ValueError("Invalid package request.")
    if job["action"] == "uninstall" and not re.fullmatch(r"[a-f0-9]{32}", job.get("preview") or ""):
        raise ValueError("Review the uninstall preview before confirming.")
    return job


def apply_transaction(action, names, files, target, step, preview=False):
    import dnf

    # The sack contains the installed RPM database and verified local media only.
    # Reject upgrades, obsoletes and dependency removals before applying a transaction.
    with dnf.Base() as base:
        base.conf.plugins = False
        base.conf.install_weak_deps = False
        base.conf.clean_requirements_on_remove = False
        base.fill_sack(load_system_repo=True, load_available_repos=False)
        if action == "install":
            candidates = base.add_remote_rpms([str(path) for path in files])
            for name in names:
                matches = [pkg for pkg in candidates if pkg.name == name
                           and pkg.arch in (target["arch"], "noarch")]
                if len(matches) != 1:
                    raise ValueError("Expected one matching local RPM for " + name + ".")
                base.package_install(matches[0])
        else:
            for package in base.sack.query().installed().filter(name=list(names)):
                base.package_remove(package)
        base.resolve(allow_erasing=False)
        adding = {pkg.name for pkg in base.transaction.install_set}
        removing = {pkg.name for pkg in base.transaction.remove_set}
        if action == "install" and (removing or not set(names) <= adding):
            raise ValueError("Installation would replace existing packages or omit a requested package.")
        if action == "uninstall" and (adding or removing != set(names)):
            other = sorted(adding | (removing - set(names)))
            raise ValueError(
                "Removal would also change these RPMs: " + ", ".join(other)
                if other else "The installed RPM selection changed. Review removal again."
            )
        planned = sorted(adding if action == "install" else removing)
        step("Transaction checked for changes to existing packages.", "Affected RPMs: " + ", ".join(planned))
        if not preview:
            base.do_transaction()


def removal_usage(config, names):
    paths = set(subprocess.check_output(["/usr/bin/rpm", "-ql", *names], text=True).splitlines())
    # Check actual paths so a separate native installation does not block removal of an idle RPM.
    units = sorted(Path(path).name for path in paths if
                   path.startswith("/usr/lib/systemd/system/") and path.endswith(".service"))
    blockers = []
    if "icct-nms-guacamole-strict" in names:
        service = subprocess.run(
            ["/usr/bin/systemctl", "is-enabled", "icct-nms-guacd.service"],
            capture_output=True, text=True, timeout=5,
        ).stdout.strip()
        if service.startswith("enabled"):
            blockers.append("The ICCT NMS Guacamole service is enabled for SSH sessions.")
    for unit in units:
        state = subprocess.run(
            ["/usr/bin/systemctl", "is-active", unit], capture_output=True, text=True, timeout=5,
        ).stdout.strip()
        if state not in {"inactive", "failed", "unknown"}:
            blockers.append(f"{unit} is {state or 'in an unknown state'}.")
    running = set()
    for process in Path("/proc").iterdir():
        if process.name.isdigit():
            try:
                executable = os.readlink(process / "exe")
            except OSError:
                continue
            if executable in paths:
                running.add(executable)
    blockers.extend(f"{path} is running." for path in sorted(running))
    script = Path(config["root"]) / "plugins/icct_nms/installation/cli/package_usage.php"
    result = subprocess.run(
        ["/usr/sbin/runuser", "-u", pwd.getpwuid(config["uid"]).pw_name, "--",
         "/usr/bin/php", str(script), *names], capture_output=True, text=True, timeout=15,
    )
    if result.returncode:
        raise ValueError("Could not verify which ICCT NMS features use these RPMs.")
    blockers.extend(json.loads(result.stdout))
    return blockers


def removal_plan(config, requested, state, receipts, protected):
    installed = state["installed"]
    eligible = set(state["removable"])
    packages = sorted(eligible.intersection(requested))
    skipped = {}
    for name in sorted(set(requested).intersection(installed) - eligible):
        skipped[name] = (
            "Protected collector dependency" if name in protected else
            "Changed since this runner installed it" if name in receipts else
            "Managed outside this package runner"
        )
    for name in sorted(set(requested).intersection(state.get("external", {}))):
        skipped[name] = "Installed outside RPM. Remove it through the original installer."
    if packages:
        blockers = removal_usage(config, packages)
    else:
        blockers = ["No eligible installed RPMs in this selection."]
        if len(requested) == 1 and set(skipped).intersection(installed):
            blockers.extend(removal_usage(config, list(skipped)))
    return {
        "packages": packages,
        "versions": {name: installed[name] for name in packages},
        "impacts": {name: REMOVAL_IMPACTS.get(name, f"Tools supplied by {name}") for name in packages},
        "skipped": skipped,
        "blockers": blockers,
    }


def work(target):
    config = json.loads((STATE / "config.json").read_text())
    grouped, tools, protected = package_scope(target)
    groups = MANIFEST.get("rhel_package_groups", {})
    media = Path(config["media"])
    names = set().union(*map(set, grouped.values()))
    path = STATE / "public/status.json"
    pending = STATE / "requests/pending.json"
    receipts_path = STATE / "receipts.json"
    receipts = json.loads(receipts_path.read_text()) if receipts_path.exists() else {}
    state = json.loads(path.read_text()) if path.exists() else {}
    job = state.get("job")

    def publish():
        write_json(path, dict(state, root=config["root"], time=int(time.time()), job=job), 0o644)

    def refresh():
        actual = installed_packages(names)
        state["installed"] = actual
        state["versions"] = package_versions(media, target, grouped, actual)
        state.pop("native", None)
        state["external"] = external_installations(target, actual)
        state["removable"] = sorted(
            name for name, versions in actual.items()
            if name not in protected and versions == receipts.get(name)
        )

    interrupted = job and job["status"] == "running"
    if interrupted:
        job["status"] = "failed"
        job["steps"].append("The runner stopped before verifying the result. Check installed packages before retrying.")
        pending.unlink(missing_ok=True)
    if not pending.is_symlink() and not pending.exists():
        if time.time() - state.get("time", 0) >= 12 or interrupted:
            refresh()
            publish()
        return
    try:
        request = read_request(pending, config["uid"])
    except (OSError, ValueError, TypeError) as error:
        LOGGER.warning("Rejected package request: %s", error)
        pending.unlink(missing_ok=True)
        publish()
        return
    previous_job = job
    job = dict(request, status="running", steps=[], completed=0, total=4)

    def step(*messages):
        job["steps"].extend(messages)
        job["completed"] += 1
        publish()

    try:
        selection = job["target"]
        if job["action"] == "verify":
            if selection != "media":
                raise ValueError("Unknown media verification request.")
            native_components = [name for name in NATIVE_EXECUTABLES if (media / "native" / name).exists()]
            job["total"] = len(grouped) + len(native_components)
            for profile in grouped:
                files = verified_media(Path(config["media"]), target, profile)
                step(f"{profile}: verified {len(files)} local RPMs.")
            for component in native_components:
                data = native_media(media, target, component)
                step(f"{component}: verified {len(data['files'])} native files.")
            refresh()
            job["status"] = "complete"
            publish()
            return
        if selection.startswith("rpm:") and selection[4:] in tools:
            profile, requested = tools[selection[4:]], [selection[4:]]
        elif selection in grouped:
            profile, requested = selection, grouped[selection]
        elif selection in groups:
            profile, requested = "base", groups[selection]["packages"]
            if not set(requested) <= set(grouped["base"]):
                raise ValueError("Package group is outside the base media scope.")
        else:
            raise ValueError("Unknown offline package selection.")
        refresh()
        installed = state["installed"]
        if job["action"] in ("preview-uninstall", "uninstall"):
            plan = removal_plan(config, requested, state, receipts, protected)
            if job["action"] == "preview-uninstall":
                if not plan["blockers"]:
                    try:
                        apply_transaction("uninstall", plan["packages"], [], target, lambda *_: None, preview=True)
                    except Exception as error:
                        plan["blockers"].append(str(error)[:1000])
                plan["expires"] = int(time.time()) + 180
                job.update(status="complete", removal=plan)
                publish()
                return
            reviewed = (previous_job or {}).get("removal", {})
            if ((previous_job or {}).get("id") != job["preview"]
                    or (previous_job or {}).get("target") != selection
                    or reviewed.get("expires", 0) <= time.time() or reviewed.get("blockers")
                    or reviewed.get("versions") != plan["versions"]
                    or reviewed.get("skipped") != plan["skipped"]):
                raise ValueError("The uninstall review expired or the package selection changed. Review it again.")
            if plan["blockers"]:
                raise ValueError("Uninstall blocked. " + " ".join(plan["blockers"]))
        selected = sorted(
            name for name in requested
            if (name not in installed and name not in state["external"]
                if job["action"] == "install" else name in state["removable"])
        )
        step("Checked installed RPMs for this selection.", "Requested: " + ", ".join(requested))
        if job["action"] == "install":
            files = verified_media(Path(config["media"]), target, profile) if selected else []
            step("Verified local RPM hashes and OS release and architecture." if files
                 else "All requested packages are already installed.")
        else:
            files = []
            step("Verified installation receipts for: " + ", ".join(selected))
        if selected:
            apply_transaction(job["action"], selected, files, target, step)
            if job["action"] == "install" and "icct-nms-pathchar" in selected:
                path = Path(native.PROFILES["pathchar"]["prefix"]) / NATIVE_EXECUTABLES["pathchar"]
                gid = config.get("gid", pwd.getpwuid(config["uid"]).pw_gid)
                if path.stat().st_gid != gid:
                    os.chown(path, 0, gid)
                    run(["/usr/sbin/setcap", "cap_net_raw=ep", path])
        else:
            step("No RPM transaction was needed.")
        actual = installed_packages(names)
        for name in selected:
            if job["action"] == "install":
                if name not in actual:
                    raise ValueError("RPM database did not confirm installation of " + name + ".")
                receipts[name] = actual[name]
            else:
                if name in actual:
                    raise ValueError("RPM database still lists " + name + ".")
                receipts.pop(name, None)
        if selected:
            write_json(receipts_path, receipts)
        refresh()
        job["status"] = "complete"
        step("Verified the result in the RPM database.")
    except Exception as error:
        LOGGER.exception("Package job failed")
        job["status"] = "failed"
        job["steps"].append(str(error)[:2000])
        try:
            refresh()
        finally:
            publish()
    finally:
        pending.unlink(missing_ok=True)


def main():
    check_python_runtime()
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="action", required=True)
    install = commands.add_parser("provision")
    install.add_argument("--media", required=True)
    install.add_argument("--cacti-root", required=True)
    install.add_argument("--web-user", default="apache")
    install.add_argument("--web-group")
    commands.add_parser("work")
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.error("Run as the system administrator.")
    target = os_info()
    if target["family"] != "rhel" or not Path("/run/systemd/system").is_dir():
        parser.error("This offline RPM runner requires a RHEL-family host with systemd.")
    STATE.mkdir(mode=0o755, exist_ok=True)
    with (STATE / "lock").open("w") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            if args.action == "work":
                return
            raise ValueError("The package runner is busy. Wait for the current job to finish.")
        if args.action == "provision":
            provision(args, target)
        else:
            work(target)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        sys.exit(str(error))
