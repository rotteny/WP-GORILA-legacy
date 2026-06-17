FROM node:20-alpine

WORKDIR /usr/src/app

RUN apk add --no-cache git python3 make g++

COPY package.json ./
RUN npm install --omit=dev

COPY . .

RUN mkdir -p /usr/src/app/auth_info

EXPOSE 3000

CMD ["node", "index.js"]
