# Pchar installation on offline RHEL

Installed and tested on the RHEL 9.8 aarch64 VM on 23 September 2026 for NMS 1.10.77. The plugin uses pchar when pathchar is absent. This is a source build of pchar 1.5, not a Red Hat RPM.

## Source and compatibility

Upstream source and documentation: https://www.kitchenlab.org/www/bmah/Software/pchar/

Source archive: https://www.kitchenlab.org/www/bmah/Software/pchar/pchar-1.5.tar.gz

Upstream pchar is no longer actively developed. A two-line compiler compatibility patch changes ambiguous unsigned abs expressions to floating-point differences before fabs. The patch is saved alongside this guide as pchar-gcc11.patch. This build was tested on ARM64; rebuild and validate on an x86_64 collector rather than copying the ARM64 binary.

## Offline build

Download the source on a connected machine, then transfer it and pchar-gcc11.patch to the offline collector. Use the matching RHEL installation DVD or approved local package repository for build dependencies. The commands below run in an administrator shell and omit sudo. Repository names depend on the local DVD configuration; these names are those on the verified VM.

```sh
# Administrator shell: install compiler packages from the mounted DVD only.
dnf --disablerepo='*' \
  --enablerepo=rhel9-dvd-baseos \
  --enablerepo=rhel9-dvd-appstream install gcc-c++ make patch
```

Build as an ordinary account, in the directory containing the transferred files:

```sh
tar -xzf pchar-1.5.tar.gz
cd pchar-1.5
patch -p1 < ../pchar-gcc11.patch
./configure --prefix=/usr/local
make -j2
./pchar -V
```

Install as administrator. Replace COLLECTOR_GROUP with the existing group of the Cacti collector account. The verified VM uses apache.

```sh
install -o root -g COLLECTOR_GROUP -m 0750 pchar /usr/local/sbin/pchar
restorecon /usr/local/sbin/pchar
setcap cap_net_raw=ep /usr/local/sbin/pchar
getcap /usr/local/sbin/pchar
```

The file capability gives this executable raw-socket access; it is not a setuid-root installation. Its file permissions restrict execution to root and the collector group. A collector under a more restrictive SELinux or service policy may still be denied. No SELinux policy, firewall setting, sudo rule or permanent service was changed on the VM. Replacing the binary removes its capability, so verify it after reinstalling.

## Plugin verification

Allow up to 60 seconds for the existing diagnostic listener to refresh its executable inventory, then reload Protocol checks. Pathchar capacity estimate should show Installed on collector. Select a device and run the test; the command displayed in Technical output identifies pchar and the selected target. No site IP is embedded in the plugin.

NMS runs three repetitions with 128-byte packet-size increments, honours the profile hop limit and stops after 60 seconds. This is a short estimate with fewer samples than upstream defaults. Slow or filtered routes may produce partial output or timeout. Results are estimates, not guaranteed usable bandwidth.

The VM test used its dynamically discovered local interface address. The test completed under the apache account; it verifies that the program and plugin can execute. It does not establish capacity of an external network link. Use a permitted remote endpoint for that measurement. The Description tab retains a review warning for capacity estimates.

## Offline bundle

On this Mac, the source archive, compiler patch, aarch64 binary and checksums are saved under VirtualBox VMs/Cacti-RHEL9/offline-packages/pchar. The source and binary are kept outside the plugin repository. The Git download includes the plugin integration, build patch and this guide; it does not automatically install pchar.

## Remove executable permission or uninstall

As administrator:

```sh
setcap -r /usr/local/sbin/pchar
# To uninstall the locally built executable:
rm /usr/local/sbin/pchar
```
