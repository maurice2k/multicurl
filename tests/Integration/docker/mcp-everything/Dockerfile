FROM node:20-slim

WORKDIR /app

RUN npm install -g @modelcontextprotocol/server-everything@latest

EXPOSE 3001

CMD ["npx", "@modelcontextprotocol/server-everything", "streamableHttp"]
