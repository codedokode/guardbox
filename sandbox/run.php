<?php 

error_reporting(-1);
ini_set('display_errors', 'Off');
ini_set('log_errors', 'stderr');

function exception_error_handler($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
}
set_error_handler("exception_error_handler");


/**
 * Runs a program in a sandbox
 */
function printUsage() {
    echo "php script.php -- /usr/bin/command --arg --arg\n";
}



