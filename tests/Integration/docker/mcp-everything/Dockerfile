FROM node:24-bookworm-slim

WORKDIR /app

# Install git for cloning and build dependencies
RUN rm -f /etc/apt/sources.list.d/* && \
    echo "deb http://deb.debian.org/debian bookworm main" > /etc/apt/sources.list && \
    echo "deb http://deb.debian.org/debian-security bookworm-security main" >> /etc/apt/sources.list && \
    apt update && apt install -y git && rm -rf /var/lib/apt/lists/*

# Clone the repository
RUN git clone https://github.com/modelcontextprotocol/servers.git . && git checkout 5f36973

# Navigate to the everything server directory and install dependencies
WORKDIR /app/src/everything

RUN npm install

# Build the project
RUN npm run build

EXPOSE 3001

CMD ["node", "dist/index.js", "streamableHttp"]
