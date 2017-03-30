#!/bin/bash

ME=`dirname $0`
ROOT=`realpath $ME/..`
INIT=${ROOT}/bootstrap/initTest.php

PHPUNIT=`which phpunit`
if [ "$PHPUNIT" == "" ]
then
    echo "Please make sure the phpunit command is in the path"
fi

echo "**** Running test $1..."
$PHPUNIT --bootstrap ${INIT} $@
if [ "$?" -ne 0 ]
then
    echo "**** Test failed!"
    exit 1
fi
echo "**** Test passed."
echo ""
