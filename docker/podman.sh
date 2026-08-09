#!/usr/bin/bash

mkdir -p /tmp/db_data

podman run -d \
  --name mariadb \
  -it \
  --network host \
  -e MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-pw}" \
  -e MYSQL_DATABASE=miserend \
  -e MYSQL_USER="${MYSQL_USER:-user}" \
  -e MYSQL_PASSWORD="${MYSQL_PASSWORD:-pw}" \
  -v "$(pwd)/mysql/mysql.conf.d:/etc/mysql/mysql.conf.d" \
  -v "/tmp/db_data:/var/lib/mysql" \
  -v "$(pwd)/mysql/initdb.d:/docker-entrypoint-initdb.d" \
  mariadb:12

mkdir -p /tmp/elasticsearch_data

podman run -d \
  --name elasticsearch \
  -it \
  --network host \
  -e discovery.type=single-node \
  -e ES_JAVA_OPTS="-Xms512m -Xmx512m" \
  -e xpack.security.enabled=false \
  -v "/tmp/elasticsearch_data:/usr/share/elasticsearch/data" \
  docker.elastic.co/elasticsearch/elasticsearch:8.10.1

podman run -d \
  --name kibana \
  -it \
  --network host \
  -e ELASTICSEARCH_HOSTS=http://localhost:9200 \
  -e XPACK_SECURITY_ENABLED=false \
  docker.elastic.co/kibana/kibana:8.10.1


podman run -d \
  --name miserend \
  -it \
  --network host \
  -e MYSQL_MISEREND_HOST=127.0.0.1 \
  -e MYSQL_MISEREND_USER="${MYSQL_MISEREND_USER:-root}" \
  -e MYSQL_MISEREND_PASSWORD="${MYSQL_MISEREND_PASSWORD:-pw}" \
  miserend:latest
