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

function prepare_environment() 
{
    rootfs="$1"
    size="$2"
    mode=0744
    owner=root:root

    # create a tmp filesystem
    mount -t tmpfs -o "size=$size,mode=0744,uid=0,gid=0" none "$rootfs"

    # create bind ro noexec mounts 
    for path in "${BIND_MOUNTS[@]}"
    do 
        targetPath="$rootfs"/"$path"
        if [ -d "$path" ]
        then 
            mkdir -m "$mode" -p "$targetPath"
            chmod --preserve-root "$mode" "$targetPath"
            chown --preserve-root "$owner" "$targetPath"
            nodevOption=,nodev
        else 
            parentPath=`dirname "$targetPath"`
            mkdir -m "$mode" -p "$parentPath"
            chown --preserve-root "$owner" "$parentPath"
            chmod --preserve-root "$mode" "$parentPath"
            touch "$targetPath"
            nodevOption=
        fi

        log "Bind-mount $path -> $targetPath"
        mount "--bind,ro,noexec,nosuid,noatime,nodiratime{$nodevOption}" "$path" "$targetPath"
    done

    # make a tmp dir 
    targetTmp="$rootfs/tmp"
    mkdir -p -m 0766 "$targetTmp"
    chown --preserve-root "$owner" "$targetTmp"
    chmod --preserve-root 0766 "$targetTmp"
}


workerId="$1"
shift 

if ! [[ "$workerId" =~ "^$" ]]
then 
    fail "Invalid workerId passed. Usage: ./script 123 command -arg -arg"
fi

# username="worker-$workerId"
rootfs="$GUARDBOXES_DIR/$username"

if findmnt --noheadings -f "$rootfs" > /dev/null 
then 
    fail "Directory $rootfs is mounted, run cleanup first"    
    exit 1
fi

prepare_environment "$rootfs" "$ROOTFS_SIZE"

