# Topology device movement

Version 1.10.87

- Drag a device or switch body to move that device. Its connections follow it.
- Drag blank map space to pan the map.
- The cursor shows an open hand over movable devices and a closed hand during dragging.
- Device positions are saved, including the core switch. Drawing links no longer resets switch positions.
- Read-only users can inspect devices but cannot move them.
- Selecting a switch port retains its existing port inspection behavior.

Validation: `node tests/topology/drag_test.cjs` checks device movement, pointer offset, independent panning, saving, cancellation and read-only behavior. PHP syntax was checked on the RHEL VM. Automated event tests do not replace an interactive browser check.

Repository: https://github.com/SKhan009/cacti-nms-plugin
