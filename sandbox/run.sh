#!/bin/bash

set -e
cd `dirname "$0"`
source config.env

function log()
{
    echo "$@"
}

function fail()
{
    echo "$@" >&"$STATUS_FD"
    exit 1
}

function timeout_helper()
{
    sleep "$1"
    kill -9 "$2" && log "Had to kill PID $2 on timeout"
}

workerId="$1"
shift 

if ! [[ "$workerId" =~ "^$" ]]
then 
    fail "Invalid workerId passed. Usage: ./script 123 command -arg -arg"
fi

username="worker-$workerId"
userId=`id -u "$username"`
groupId=`id -g "$username"`
rootfs="$GUARDBOXES_DIR/$username"

if ! id "$username" 2>/dev/null >/dev/null
then 
    fail "User $username not found"
fi

log "Applying ulimits"
# Run a command in a sandbox with timeout
ulimit -H -t "$LIMIT_CPU_SECONDS" \
          -d "$LIMIT_MEMORY" \
          -n "$LIMIT_FILES" \
          -u "$LIMIT_PROCESSES" \

timeout_helper "$WAIT_TIME" "$BASHPID" & 
log "Started a timeout helper"

exec env -i PATH=/bin:/usr/bin HOME=/none "USER=$username" LANG=en_US.utf8 \
    "$GUARDDOG_PATH" --config="$GUARDDOG_CONFIG" \
    --verbose --status-fd="$STATUS_FD"\
    --chroot-path="$rootfs" --set-uid="$userId" --set-gid="$groupId"\
    -- "$@"




