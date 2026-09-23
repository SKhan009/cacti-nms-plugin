# NMS 1.10.80

Connection editor: two-column form, matching primary Save/Filter buttons, outlined Cancel/Reset buttons, aligned search actions and pagination, visibly disabled page buttons, and responsive wrapping.

Pathchar/pchar descriptions now classify local tests and report available per-hop timing/capacity and sent/replied probe counts. Zero capacity is shown as unavailable. Completed local tests are labelled Local self-test completed; no physical link capacity is claimed. Remote missing/zero estimates, packet loss, incomplete output, negative slopes and poor reported regression fit (r2 below 0.5, a conservative display rule) produce Review required. Execution failures remain red. Unknown output remains a warning. Technical output is preserved.

Historical literal loopback results are recognised without running another test. New Pathchar runs also save whether the selected IP belongs to the actual collector interfaces. Older results targeting other collector addresses cannot be retrospectively classified from the target alone.

VM configuration: one manual Ethernet map connection between NMS Final QA offline and Cacti RHEL 9 Local, with device-only endpoints and unknown capacity. No physical port or measured speed was invented.

Validation: summary regression fixtures cover IPv4/IPv6 loopback, remote success, loss, missing capacity, poor fit and failure. VM result 13 now reports Local self-test completed, 33 probes and 33 replies, eight seconds, and network capacity not applicable. PHP syntax and server rendering passed.
