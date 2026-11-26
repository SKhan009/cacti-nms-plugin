#!/usr/bin/env python3
"""Command-line entry point for the shared ICCT NMS installer."""
from pathlib import Path
import runpy

if __name__ == '__main__':
    source = Path(__file__).resolve().parent
    plugin = source if (source / 'setup.php').is_file() else source / 'icct_nms'
    runpy.run_path(str(plugin / 'installation/cli/launcher.py'), run_name='__main__')
