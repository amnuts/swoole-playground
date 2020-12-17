# extend the swoole dockerfile
FROM phpswoole/swoole:4.5.9-php8.0-alpine

# Copy the local package files to the container's workspace
ADD . /app

ENV DOCKER 1
ENV PORT 8080
ENV HOST=0.0.0.0
ENV HOSTNAME=docker.local

# Set the working directory to avoid relative paths after this
WORKDIR /app

# Run the command by default when the container starts
ENTRYPOINT php websocket.php start

# Document that the service runs on port 8080
EXPOSE 8080
