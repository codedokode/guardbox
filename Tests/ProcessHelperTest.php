<?php

namespace Tests;

use Codebot\Util\ProcessHelper;
use React\Stream\ThroughStream;
use Tests\Helper\CapturingLogger;

class ProcessHelperTest extends \PHPUnit\Framework\TestCase
{
    public function testPipeStreamToLog()
    {
        $source = new ThroughStream();
        $logger = new CapturingLogger();

        ProcessHelper::pipeStreamToLogger($source, $logger, 'test-prefix');
        $source->write("test-msg-1");
        $source->end("test-msg-2");

        $content = implode("\n", $logger->getMessages());
        $this->assertContains('test-prefix', $content);
        $this->assertContains('test-msg-1', $content);
        $this->assertContains('test-msg-2', $content);
    }

    public function testBuildCommandLine()
    {
        // Build a command line and check that it prints back same data
        $print = '#$\'\\$"';
        $args = ['echo', '-n', $print];
        $line = ProcessHelper::buildCommandLine($args);
        exec($line, $output, $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertEquals(1, count($output));
        $this->assertEquals($print, $output[0]);
    }

    public function testBuildShellCommand()
    {
        $print = '#$\'\\$"';
        $cmdLine = ProcessHelper::buildCommandLine(['echo', '-n', $print]);
        $shellCmd = ProcessHelper::createShellCommand($cmdLine);
        $shellCmdLine = ProcessHelper::buildCommandLine($shellCmd);

        exec($shellCmdLine, $output, $exitCode);
        $this->assertEquals(0, $exitCode);
        $this->assertEquals(1, count($output));
        $this->assertEquals($print, $output[0]);
    }
    
    
}