ARG BASE=grafana/k6:1.3.0@sha256:3ddc8b1a33a2c3d8edc6e99b6a762ae36cba08788463458f5e6a7703e14eb77d
FROM ${BASE}
USER 0
RUN --network=none sed -i 's/"binary_sha256":"[a-f0-9]*"/"binary_sha256":"0000000000000000000000000000000000000000000000000000000000000000"/' /opt/lbg-k6/provenance.json
