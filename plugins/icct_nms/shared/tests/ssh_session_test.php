<?php

require __DIR__ . '/../../protocols/ssh/services/backend/ssh.php';
function db_execute_prepared($sql, $parameters)
{
    return $GLOBALS['databaseAvailable'];
}
$GLOBALS['databaseAvailable'] = true;
icct_backend_ssh_close_session(str_repeat('a', 64), 'Disconnected by user');
$GLOBALS['databaseAvailable'] = false;
try {
    icct_backend_ssh_close_session(str_repeat('a', 64), 'Disconnected by user');
    throw new LogicException('Failed revocation was reported as closed.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'could not be closed')) {
        throw $error;
    }
}
echo "PASS: failed authorization revocation is explicit; no false disconnected success.\n";
