<?php

namespace Codebot\Util;

use Psr\Log\LoggerInterface;
use React\Stream\ReadableStreamInterface;

class ProcessHelper
{
    /**
     * Redirects all data from stream to logger with adding optional prefix
     */
    public static function pipeStreamToLogger(ReadableStreamInterface $readFrom, LoggerInterface $logger, $prefix)
    {
        $readFrom->on('error', function ($e) use ($logger) {
            $logger->error('{name}: Read error on child script pipe: {error}', [
                $this->getName(),
                $e->getMessage()
            ]);
        }); 

        $endedWithNl = true;
        $readFrom->on('data', function ($data) use ($logger, $prefix, &$endedWithNl) {
            if ($data === '') {
                return;
            }

            $annotatedOutput = self::annotateLines($data, $prefix, $endedWithNl);
            foreach ($annotatedOutput as $line) {
                $logger->debug($line);
            }
            
        });
    }

    private static function annotateLines($data, $tag, &$endedWithNl)
    {
        $endsWithNl = preg_match("/\n\Z/", $data); 
        if ($endsWithNl) {
            $data = substr($data, 0, -1);
        }

        $lines = explode("\n", $data);
        if (!$endedWithNl) {
            $lines[0] = '...' . $lines[0];
        }

        if (!$endsWithNl) {
            $last = count($lines) - 1;
            $lines[$last] .= '...';
        }

        foreach ($lines as &$line) {
            $line = $tag . $line;
        }

        $endedWithNl = $endsWithNl;

        return $lines;

        // $result = $tag . ($endedWithNl ? '' : '...') . 
        //     str_replace("\n", "\n{$tag}", $data) . 
        //     ($endsWithNl ? '' : '...') . "\n";

        
        // return $result;
    }

    /**
     * Make a single line with command from arguments escaping all special characters
     */
    public static function buildCommandLine(array $args)
    {
        $parts = [];
        foreach ($args as $arg) {
            $parts[] = self::quoteShellArg($arg);
        }

        return implode(" ", $parts);
    }

    private static function quoteShellArg($arg)
    {
        // No need for quotes
        if (preg_match("!\A[a-zA-Z0-9_/.\-]+\Z!", $arg)) {
            return $arg;
        }

        // TODO: we can use Symfony\Component\Process\ProcessUtils::escapeArgument
        return escapeshellarg($arg);
    }

    public static function substituteArguments(array $args, array $substitute)
    {
        foreach ($args as &$arg) {
            $arg = preg_replace_callback("/\{[a-zA-Z0-9_\-]+\}/", 
                function ($m) use ($substitute) {
                    if (!array_key_exists($m[1], $substitute)) {
                        throw new \Exception("There is no value for key '{$m[1]}'");
                    }

                    return $substitute[$m[1]];
            }, $arg);
        }

        return $args;
    }

    public static function createShellCommand($command)
    {
        return ['exec', 'sh', '-c', $command];
    }
}