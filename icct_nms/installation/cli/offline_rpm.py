"""Install missing requirements from verified local RPMs without replacing Cacti's packages."""
from pathlib import Path


def install_missing(files, names, target, preview=False):
    try:
        import dnf
    except ImportError as error:
        raise ValueError('Use the RHEL system Python (/usr/bin/python3) with python3-dnf installed.') from error

    paths = [Path(path).resolve(strict=True) for path in files]
    if not paths or any(not path.is_file() or path.suffix != '.rpm' for path in paths):
        raise ValueError('Verified local RPM files are required.')
    with dnf.Base() as base:
        base.conf.plugins = False
        base.conf.install_weak_deps = False
        base.conf.clean_requirements_on_remove = False
        # Never read repository configuration or download metadata/packages.
        base.fill_sack(load_system_repo=True, load_available_repos=False)
        installed = {pkg.name for pkg in base.sack.query().installed()
                     if pkg.arch in (target['arch'], 'noarch')}
        missing = sorted(set(names) - installed)
        if not missing:
            print('Required packages already installed; reusing existing dependencies.', flush=True)
            return []
        candidates = base.add_remote_rpms([str(path) for path in paths])
        for name in missing:
            matches = [pkg for pkg in candidates if pkg.name == name
                       and pkg.arch in (target['arch'], 'noarch')]
            if len(matches) != 1:
                raise ValueError('Expected one compatible bundled RPM for ' + name + '.')
            base.package_install(matches[0])
        base.resolve(allow_erasing=False)
        adding = {pkg.name for pkg in base.transaction.install_set}
        removing = {pkg.name for pkg in base.transaction.remove_set}
        # Covers upgrades, downgrades, obsoletes, reinstalls and parallel versions.
        replaced = removing | (adding & installed)
        if replaced:
            raise ValueError('Offline dependencies would replace installed packages: ' +
                             ', '.join(sorted(replaced)) +
                             '. Rebuild compatible media; existing Cacti packages were preserved.')
        if not set(missing) <= adding:
            raise ValueError('The offline transaction omitted a required package.')
        planned = sorted(adding)
        print('Missing packages and dependencies: ' + ', '.join(planned), flush=True)
        if not preview:
            base.do_transaction()
        return planned
