<?php 

namespace Tests\Helper;

class TestProcessHelper
{
    public static function killDescendantProcesses()
    {   
        $pids = self::getDescendantIds();
        if (!$pids) {
            return;
        }

        printf("Send SIGTERM, pids alive: %s\n", implode(', ', $pids));
        self::killChildren(15);
        usleep(500000);
        $pids = self::getDescendantIds();
        if (!$pids) {
            return;
        }

        printf("Send SIGKILL, pids alive %s\n", implode(', ', $pids));
        self::killChildren(9);
        usleep(500000);
        $pids = self::getDescendantIds();
        if (!$pids) {
            return;
        }

        printf("These processes are still alive: %s\n", implode(', ', $pids));
    }

    private static function getDescendantIds()
    {
        $pid = getmypid();
        exec("exec pgrep --parent $pid", $output, $exitCode);
        /*
               0      One or more processes matched the criteria.
               1      No processes matched.
               2      Syntax error in the command line.
               3      Fatal error: out of memory etc.
         */
        // 127 = command not found
        if ($exitCode != 0 && $exitCode != 1) {
            throw new \Exception("pgrep returned code $exitCode, output: \n" . implode("\n", $output));
        }

        $output = array_map('trim', $output);
        if (!$output) {
            return [];
        }

        return $output;
    }

    private static function killChildren($signal)
    {
        $pid = getmypid();
        exec("exec pkill --signal $signal --parent $pid", $output, $exitCode);
        /*
               0      One or more processes matched the criteria.
               1      No processes matched.
               2      Syntax error in the command line.
               3      Fatal error: out of memory etc.
         */
        // 127 = command not found
        if ($exitCode != 0 && $exitCode != 1) {
            throw new \Exception("pkill returned code $exitCode, output: \n" . implode("\n", $output));
        }

        if ($exitCode == 1) {
            printf("pkill found no processes to kill\n");
        }

        return $exitCode;
    }

    // private static function killPids(array $ids, $signal)
    // {
    //     foreach ($ids as $id) {
    //         if (!posix_kill($id, $signal)) {
    //             printf("Failed to deliver signal $signal to PID $id\n");
    //         }
    //     }
        // exec("kill -s $signal " . implode(' ', $ids), $dummy, $exitCode);
        // if ($exitCode != 0) {
        //     printf("Kill exit code was $exitCode\n");
        // }
    // }
}