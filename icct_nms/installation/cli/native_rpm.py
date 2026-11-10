#!/usr/bin/env python3
"""Wrap an approved RHEL native bundle in an RPM without changing its payload."""

import argparse
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

import native
from setup import component_packages, os_info

REQUIRES = {
    "pathchar": "glibc, libstdc++, libcap",
    "guacamole-strict": "glibc, cairo, pango, libjpeg-turbo, libpng, libssh2, libuuid, openssl-libs, zlib",
}


def build(bundle, output, rpmbuild):
    target = os_info()
    if target["family"] != "rhel":
        raise ValueError("Build the RPM on the matching RHEL host.")
    data = native.verify_bundle(bundle, target)
    component = data["component"]
    package, = component_packages(target, component)
    prefix = native.PROFILES[component]["prefix"]
    paths = []
    if output.exists() and any(output.iterdir()):
        raise ValueError("Use an empty RPM bundle directory.")
    with tempfile.TemporaryDirectory(prefix="icct-rpmbuild-") as temporary:
        root = Path(temporary)
        for name, details in data["files"].items():
            if not re.fullmatch(r"[A-Za-z0-9_./+-]+", name):
                raise ValueError("Unsupported RPM payload path.")
            payload = root / "SOURCES/payload" / name
            payload.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(bundle / "payload" / name, payload)
            payload.chmod(details["mode"])
            attributes = "%attr(0750,root,apache) %caps(cap_net_raw=ep) " if (
                component == "pathchar" and name == "sbin/pchar"
            ) else ""
            paths.append(attributes + prefix + "/" + name)
        spec = root / "native.spec"
        spec.write_text(f"""%global __os_install_post %{{nil}}
%global debug_package %{{nil}}
%global _build_id_links none
Name: {package}
Version: {data['version']}
Release: 1.el9
Summary: ICCT NMS {component} runtime
License: {'BSD' if component == 'pathchar' else 'Apache-2.0'}
AutoReqProv: no
Requires: {REQUIRES[component]}

%description
Verified {component} payload for ICCT NMS.

%install
mkdir -p %{{buildroot}}{prefix}
cp -a %{{_sourcedir}}/payload/. %{{buildroot}}{prefix}/

%files
""" + "\n".join(paths) + "\n")
        subprocess.run([rpmbuild, "-bb", "--define", f"_topdir {root}", str(spec)], check=True)
        rpm, = (root / "RPMS" / target["arch"]).glob("*.rpm")
        output.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(rpm, output / rpm.name)
        manifest = dict(format=1, target=target, component=component, packages=[package],
                        files={rpm.name: native.digest(rpm)})
        (output / "bundle.json").write_text(json.dumps(manifest, indent=2) + "\n")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--native-bundle", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--rpmbuild", default="rpmbuild")
    args = parser.parse_args()
    build(args.native_bundle.resolve(), args.output, args.rpmbuild)
