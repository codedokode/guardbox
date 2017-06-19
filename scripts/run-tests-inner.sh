#!/bin/bash

set -e 
cd ..

function printProcesses()
{
    ps --sid $SID --forest -o pid,ppid,uid,stat,wchan,time,cmd    
}

SID=`ps -p $$ --no-headers -o sid`
echo "Created session $SID"

# ignore code
if php vendor/phpunit/phpunit/phpunit "$@"
then 
    code=$?
else
    code=$?
fi

echo "Processes left  in session:"
printProcesses

#echo "Kill processes in session"
#pkill --session $SID --echo 

#echo "Processes left  in session:"
#printProcesses

exit $code
