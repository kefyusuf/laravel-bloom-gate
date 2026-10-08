FROM golang:1.23.7-alpine@sha256:e438c135c348bd7677fde18d1576c2f57f265d5dfa1a6b26fca975d4aa40b3bb AS build
WORKDIR /src
RUN apk add --no-cache patch=2.7.6-r10
ADD https://codeload.github.com/grafana/k6/tar.gz/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb /tmp/upstream.tar.gz
COPY arrival-slot-boundary.patch /tmp/arrival-slot-boundary.patch
RUN echo '2c50056f2ae1db089b0438d161a13ba4b2e6e53497413a6e99dec2047c7c0ec0  /tmp/upstream.tar.gz' | sha256sum -c - \
    && tar -xzf /tmp/upstream.tar.gz --strip-components=1 \
    && echo '1232b8e7feded527f85ba0f7ea4e69823cbf258f53e537ad1fcbdb11bad8e4d1  /tmp/arrival-slot-boundary.patch' | sha256sum -c - \
    && patch -p1 < /tmp/arrival-slot-boundary.patch \
    && echo 'e5cbf62b0eebd7088df5090046adf83d1793fed47279810d3300546cc724ccce  lib/executor/constant_arrival_rate.go' | sha256sum -c - \
    && ! grep -E 'boundary(Contexts|Since|NewTimer|AfterTimer)' lib/executor/constant_arrival_rate.go
RUN --network=none GOTOOLCHAIN=local GOPROXY=off GOSUMDB=off CGO_ENABLED=0 go build -mod=vendor -trimpath -buildvcs=false -o /out/k6 . \
    && mkdir -p /out/provenance \
    && cp lib/executor/constant_arrival_rate.go /out/provenance/runtime.go \
    && cp /tmp/arrival-slot-boundary.patch /out/provenance/arrival-slot-boundary.patch \
    && printf '{"upstream_revision":"5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb","runtime_source_sha256":"e5cbf62b0eebd7088df5090046adf83d1793fed47279810d3300546cc724ccce","patch_sha256":"1232b8e7feded527f85ba0f7ea4e69823cbf258f53e537ad1fcbdb11bad8e4d1","binary_sha256":"%s","instrumented_clock":false,"source_archive_sha256":"2c50056f2ae1db089b0438d161a13ba4b2e6e53497413a6e99dec2047c7c0ec0","builder_image":"golang:1.23.7-alpine@sha256:e438c135c348bd7677fde18d1576c2f57f265d5dfa1a6b26fca975d4aa40b3bb","runtime_base_image":"grafana/k6:1.3.0@sha256:3ddc8b1a33a2c3d8edc6e99b6a762ae36cba08788463458f5e6a7703e14eb77d"}\n' "$(sha256sum /out/k6 | cut -d ' ' -f 1)" > /out/provenance/provenance.json

FROM grafana/k6:1.3.0@sha256:3ddc8b1a33a2c3d8edc6e99b6a762ae36cba08788463458f5e6a7703e14eb77d
COPY --from=build /out/k6 /usr/bin/k6
COPY --from=build /out/provenance /opt/lbg-k6
LABEL org.opencontainers.image.revision="5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb" \
      org.opencontainers.image.description="Task-local k6 arrival-slot boundary measurement fixture"
ENTRYPOINT ["/usr/bin/k6"]
