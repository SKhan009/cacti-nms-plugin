<?php

/** Explain what a systemd record establishes without guessing why the process failed. */
function icct_nms_syslog_systemd_event(string $message): ?array
{
    if (!preg_match('/^([A-Za-z0-9_.@:-]+\.service): (.+)$/D', $message, $parts)) {
        return null;
    }
    [, $unit, $detail] = $parts;
    $service = match ($unit) {
        'nms-diagnostic-runner.service' => 'the older NMS diagnostics listener',
        'icct-nms-diagnostics.service' => 'the ICCT NMS diagnostics collector',
        default => 'the ' . $unit . ' service',
    };
    if (preg_match('/^Scheduled restart job, restart counter is at (\d+)\.$/D', $detail, $restart)) {
        return [
            'Automatic restart scheduled for ' . $unit,
            'The system service manager (systemd) scheduled automatic restart attempt ' . $restart[1] .
                ' for ' . $service . '. The outcome of that attempt is unknown from this entry.',
        ];
    }
    if (preg_match('/^Main process exited, code=exited, status=(\d+)\/FAILURE$/D', $detail, $exit)) {
        return [
            'Service process exited with an error',
            'The main process for ' . $service . ' stopped with exit code ' . $exit[1] .
                '. The cause of the error is unknown from this entry.',
        ];
    }
    if ($detail === "Failed with result 'exit-code'.") {
        return [
            'Systemd recorded a service failure',
            'The system service manager marked ' . $service . ' as failed after its process exited with an error.',
        ];
    }
    if ($detail === 'Deactivated successfully.') {
        return [
            'Service stopped successfully',
            'The system service manager recorded that ' . $service . ' stopped successfully.',
        ];
    }
    return null;
}
