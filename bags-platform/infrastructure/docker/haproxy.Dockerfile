FROM haproxy:3.0-alpine
USER root
RUN apk add --no-cache socat curl && mkdir -p /var/run/haproxy
