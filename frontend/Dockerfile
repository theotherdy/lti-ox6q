FROM node:22-alpine

WORKDIR /app

# Copy only manifests first (for better caching when not volume-mounted)
COPY package.json package-lock.json* ./
RUN npm install

# We intentionally don't run npm install here because in dev we'll mount the source.
# The start script will install dependencies if node_modules is missing.

COPY . .

#RUN chmod +x ./start.sh

EXPOSE 5173
CMD ["sh", "start.sh"]
