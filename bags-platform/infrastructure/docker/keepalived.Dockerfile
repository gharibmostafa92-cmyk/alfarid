FROM alpine:3.21
RUN apk add --no-cache keepalived curl
CMD ["keepalived", "--dont-fork", "--log-console", "--use-file=/etc/keepalived/keepalived.conf"]
