**This project is not working yet.**

[![Build Status](https://travis-ci.org/codedokode/guardbox.svg?branch=master)](https://travis-ci.org/codedokode/guardbox)

Guardbox is an asynchronous daemon that can supervise several child processes running simultaneously. It is made to run programs in sandboxes. 

It can have HTTP interface to accept tasks to execute.

Guardbox is written in PHP using ReactPHP library. It turned out that Promises and Streams provided by ReactPHP handle errors and exceptions very poorly so currently many of the tests fail. I am thinking about writing a replacement for those libraries.

## Installation

Run `php composer.phar install`

You also might need to compile a [guarddog](https://github.com/codedokode/guarddog) utility and change some values inside config file [sandbox/config.env](./sandbox/config.env). To run tests you don't really need guarddog, but you will need to fix scripts in `sandbox` directory.

## Running unit tests

Run `./scripts/run-tests.sh`. Running phpunit directly may not work.

Currently tests fail.

## Code 

```
Codebot             # PHP code of a daemon
sandbox             # scripts that prepare a sandbox and run program inside a sandbox
scripts
Tests               # phpunit tests
```

## Sandbox operation

There are 4 scripts that are described in config object. First, sandbox is prepared by running *cleanup* and *prepare* scripts. Then, a program is executed using *run* script. And after termination, sandbox is clean up by running *cleanup* script. If the program has not terminated in time, a *kill* script is used.

## Isolation and restrictions measures

- Filesystem access - chroot
- Disk space - chroot + tmpfs
- Sending signals - guarddog(seccomp)
- Forking processes - guarddog(seccomp)
- Memory - ulimit
- CPU time - ulimit, guardboxd
- Network access - guarddog(seccomp), iptables rules
- Syscalls filtering - guarddog(seccomp)
- Environment variables leak - ? 
- File descriptors leak prevention - ? 

