FROM ubuntu:24.04 AS build
RUN apt-get update && apt-get install -y build-essential cmake pkg-config git ca-certificates libsqlite3-dev libsodium-dev
WORKDIR /app
COPY . .
RUN mkdir -p build && cd build && cmake .. && make

FROM ubuntu:24.04
RUN apt-get update && apt-get install -y libsqlite3-0 libsodium23 && rm -rf /var/lib/apt/lists/*
WORKDIR /app/build
COPY --from=build /app/build/fincaisse .
COPY --from=build /app/build/public ./public
ENV DB_PATH=/data/fincaisse.db
CMD ["./fincaisse"]
