# Classifying discovered links — NMS 1.10.84

Open **Topology → Edit topology → Connections**. On an auto-detected row, choose **Classify link**, select VSAT / Leased-line, Line-of-sight (LOS), Optical fiber or another saved type, then save. Choose **Unclassified** to clear the assignment.

The list separates discovery protocol, connection type, status, capacity and source. Classification also appears in the topology link label. Discovered endpoints remain read-only and cannot be deleted from this list. Only users with management permission can classify links, and the link must still be accessible in their topology scope.

Classification is operator-supplied metadata. It does not configure routing, identify technology automatically or change discovery status. A manual connection has Unknown (manual) status because creating a map connection does not prove reachability. Auto-detected status remains the discovery system's recorded state, including stale or inferred evidence.

Assignments persist in plugin_nms_link_classification, keyed by both device and port identities independently of link direction. A port change requires a new assignment. Renaming a connection type updates assignments; deleting an assigned type is blocked.

VM verification: classify and clear; map metadata; endpoint, state and protocol preservation; unknown-link rejection; in-use type protection. All temporary test assignments were rolled back. Existing operator configuration is preserved.

Repository: https://github.com/SKhan009/cacti-nms-plugin
