# ICCT NMS Cacti Plugin

This repository contains the standalone ICCT NMS plugin for Cacti 1.2.31.

## Install

1. Copy `plugins/icct_nms` to your Cacti installation’s `plugins/icct_nms` directory.
2. Install or upgrade and enable **ICCT NMS Inventory** in Cacti Plugin Management.
3. Grant **View ICCT NMS Inventory** and the appropriate native Cacti device permissions.
4. Open **ICCT NMS → Inventory**.

See [the plugin documentation](plugins/icct_nms/README.md) for configuration, collector requirements, and supported features.

The plugin includes Inventory, device configuration, protocol enable/disable controls, diagnostics, graph template associations, and data queries. It uses its own tables and Cacti’s native device and graph APIs.

Only the current plugin source is maintained here. Generated previews, packages, local QA files, and superseded plugins are excluded.
