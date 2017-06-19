#!/bin/bash 

set -e 
cd `dirname "$0"`

setsid ./run-tests-inner.sh "$@"
