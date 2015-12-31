#!/usr/bin/env bash
#
# ownCloud
#
# This script start a docker container to test the files_external tests
# against. It will also change the files_external config to use the docker
# container as testing environment. This is reverted in the stop step.W
#
# Set environment variable DEBUG to print config file
#
# @author Morris Jobke
# @author Robin McCorkell
# @copyright 2015 ownCloud

if ! command -v docker >/dev/null 2>&1; then
    echo "No docker executable found - skipped docker setup"
    exit 0;
fi

echo "Docker executable found - setup docker"

docker_image=xenopathic/ceph-keystone

echo "Fetch recent ${docker_image} docker image"
docker pull ${docker_image}

# retrieve current folder to place the config in the parent folder
thisFolder=`echo $0 | replace "env/start-amazons3-ceph.sh" ""`

if [ -z "$thisFolder" ]; then
    thisFolder="."
fi;

user=test
accesskey=aaabbbccc
secretkey=cccbbbaaa
bucket=testbucket
port=80

container=`docker run -d \
    -e RGW_CIVETWEB_PORT=$port \
    ${docker_image}`

host=`docker inspect $container | grep IPAddress | cut -d '"' -f 4`


echo "${docker_image} container: $container"

# put container IDs into a file to drop them after the test run (keep in mind that multiple tests run in parallel on the same host)
echo $container >> $thisFolder/dockerContainerCeph.$EXECUTOR_NUMBER.amazons3

# TODO find a way to determine the successful initialization inside the docker container
echo "Waiting 20 seconds for ceph initialization ... "
sleep 20

echo "Create ceph user"
docker exec $container radosgw-admin user create \
    --uid="$user" --display-name="$user" \
    --access-key="$accesskey" --secret="$secretkey" \
    >/dev/null

cat > $thisFolder/config.amazons3.php <<DELIM
<?php

return array(
    'run'=>true,
    'bucket'=>'$bucket',
    'hostname'=>'$host',
    'port'=>'$port',
    'key'=>'$accesskey',
    'secret'=>'$secretkey',
    'use_ssl'=>false,
    'use_path_style'=>true,
);

DELIM

if [ -n "$DEBUG" ]; then
    cat $thisFolder/config.amazons3.php
    cat $thisFolder/dockerContainerCeph.$EXECUTOR_NUMBER.amazons3
fi
