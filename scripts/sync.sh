#!/bin/bash

set -e
cd `dirname "$0"`
cd ..

if [ -z "$1" ]
then
    echo "Usage: ./script user@host"
    exit 1
fi

rsync -r -v . "$1":/tmp/gd/
