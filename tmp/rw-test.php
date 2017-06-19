<?php 

use React\EventLoop\StreamSelectLoop;
use React\Stream\Stream;

require '/tmp/test1/vendor/autoload.php';

$loop = new StreamSelectLoop;
$fd = fopen('/dev/full', 'r+');
$writer = new Stream($fd, $loop);

$errorReported = false;

$writer->on('error', function ($e) use (&$errorReported) {
    $errorReported = true;
    printf("Error: %s\n", $e->getMessage());
});

$writer->on('end', function () {
    printf("End\n");
});

$writer->on('close', function () {
    printf("Close\n");
});

$writer->write('test');
$writer->end();

$loop->run();

assert($errorReported === true);
