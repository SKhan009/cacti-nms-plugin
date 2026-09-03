#!/usr/bin/env python3
# File: nms-snmpsim-run.py
# Service entry point that loads the trusted JSON settings and execs the configured responder with literal arguments.
"""Executed by systemd as its configured unprivileged user, never by the web application."""
import os
from runtime_config import load_config


# Read the trusted configuration and replace this process with the responder using literal argument values.
def main():
    config = load_config()
    # execv uses literal arguments, not a shell command or a PHP-supplied executable.
    os.execv(config['executable'], [config['executable'],
             '--data-dir=' + config['data_dir'],
             '--agent-udpv4-endpoint=' + config['listen_address'] + ':' + str(config['port'])])


if __name__ == '__main__':
    main()
