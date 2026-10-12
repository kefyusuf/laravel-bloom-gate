"""Synthetic Docker CLI boundary for the real collector subprocess tests only."""
import hashlib
import json
import os
from pathlib import Path
import sys
import time

root = Path(os.environ["COLLECTOR_ROOT"])
args = sys.argv[1:]
with Path(os.environ["COLLECTOR_CALLS"]).open("a", encoding="utf-8") as log:
    log.write(json.dumps(args) + "\n")
state_path = root / "state.json"
state = json.loads(state_path.read_text()) if state_path.exists() else {"built": False}
scenario = os.environ["COLLECTOR_CASE"]
fixture = root / "tests/Experiments/Swoole"
results = root / ".build/generator-qualification"
image_id = "sha256:" + "a" * 64
receiver_id = "sha256:" + "b" * 64


def emit(value):
    print(json.dumps(value, separators=(",", ":")))


def save():
    state_path.write_text(json.dumps(state))


def inspect(name):
    load = name.endswith("-load")
    running = not load or not state.get("inspected", False)
    if load:
        state["inspected"] = True
        save()
    emit([{"Image": image_id if load else receiver_id,
           "HostConfig": {"NanoCpus": 4000000000 if load else 2000000000,
                          "Memory": 4294967296 if load else 268435456},
           "State": {"Running": running, "ExitCode": 0, "OOMKilled": False},
           "RestartCount": 0}])


if args[0] == "info":
    print("linux")
elif args[:2] == ["ps", "-aq"]:
    pass
elif args[:2] == ["network", "inspect"]:
    sys.exit(1)
elif args[:2] == ["image", "inspect"]:
    if "--format" not in args or ("k6" in args[2] and not state["built"]):
        sys.exit(1)
    print(image_id if "k6" in args[2] else receiver_id)
elif args[0] == "build":
    state["built"] = True
    save()
elif args[0] == "run":
    patch = hashlib.sha256((fixture / "K6Boundary/arrival-slot-boundary.patch").read_bytes()).hexdigest()
    runtime = "e5cbf62b0eebd7088df5090046adf83d1793fed47279810d3300546cc724ccce"
    binary = "c" * 64
    emit({"upstream_revision": "5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb",
          "runtime_source_sha256": runtime, "patch_sha256": patch,
          "binary_sha256": binary, "instrumented_clock": False,
          "source_archive_sha256": "2c50056f2ae1db089b0438d161a13ba4b2e6e53497413a6e99dec2047c7c0ec0",
          "builder_image": "golang:1.23.7-alpine@sha256:e438c135c348bd7677fde18d1576c2f57f265d5dfa1a6b26fca975d4aa40b3bb",
          "runtime_base_image": "grafana/k6:1.3.0@sha256:3ddc8b1a33a2c3d8edc6e99b6a762ae36cba08788463458f5e6a7703e14eb77d"})
    print(binary + "  /usr/bin/k6")
    print(runtime + "  /opt/lbg-k6/runtime.go")
    print(patch + "  /opt/lbg-k6/arrival-slot-boundary.patch")
    print("k6 v1.3.0 (go1.23.7, linux/amd64)")
elif args[0] == "inspect":
    inspect(args[1])
elif args[0] == "stats":
    for name in args[-2:]:
        emit({"Name": name, "CPUPerc": "10.0%", "MemPerc": "1.0%"})
elif args[0] in ("logs", "rm") or args[:2] == ["image", "rm"]:
    if scenario == "timeout-after-cleanup" and args[:2] == ["image", "rm"] and "k6" in args[2]:
        (results / "stalled-docker-pid.txt").write_text(str(os.getpid()))
        time.sleep(60)
    pass
elif args[0] == "compose":
    command = args[5]
    tail = args[6:]
    if command in ("build", "up", "down"):
        pass
    elif command == "run":
        env = dict(item.split("=", 1) for i, item in enumerate(tail) if i and tail[i - 1] == "-e")
        state.update({"profile": env, "inspected": False})
        save()
        name = env["OUTPUT"]
        results.mkdir(parents=True, exist_ok=True)
        now = int(time.time() * 1000)
        drops = 0 if scenario in ("invalid-negative", "timeout-after-cleanup") or "negative" not in name else 1
        cell = {"responses": 7, "dropped_iterations": drops, "p99_ms": 25,
                "warmup_seconds": int(env["WARMUP"]), "duration_seconds": int(env["DURATION"]),
                "measurement_start_epoch_ms": now - 10000, "measurement_end_epoch_ms": now + 120000}
        raw = {"metrics": {"vus_max": {"values": {"max": int(env["VUS"])}}}, "cell": cell}
        (results / (name + ".json")).write_text(json.dumps(raw))
        (results / (name + "-timeline.jsonl")).write_text(json.dumps({"status": "observed", "epoch_ms": now}) + "\n")
        (results / (name + "-generator-cgroup.txt")).write_text("synthetic cgroup boundary\n")
        print("synthetic-container")
    elif command == "exec":
        joined = " ".join(tail)
        if "SOURCE_FILE=" in joined:
            source = next(item.split("=", 1)[1] for item in tail if item.startswith("SOURCE_FILE="))
            text = (fixture / source).read_text(encoding="utf-8").replace("\r\n", "\n")
            print(hashlib.sha256(text.encode()).hexdigest())
        elif "generator-coverage.php" in joined:
            failed = scenario == "coverage-failure" and "negative" not in state["profile"]["OUTPUT"]
            emit({"verdict": "INCONCLUSIVE" if failed else "OBSERVED",
                  "reasons": ["An observation was unavailable during measurement."] if failed else []})
        elif "generator-evaluate.php" in joined:
            negative = "negative" in state["profile"]["OUTPUT"]
            if "--negative-control" in tail:
                emit({"verdict": "INVALID_NEGATIVE_CONTROL" if scenario == "unsafe-negative" else "VALID_NEGATIVE_CONTROL",
                      "reasons": ["Synthetic unsafe negative control."] if scenario == "unsafe-negative" else []})
            elif "--qualification" in tail:
                emit({"verdict": "PASS", "reasons": []})
            else:
                failed = negative and scenario not in ("invalid-negative", "timeout-after-cleanup") or scenario == "admission-failure"
                emit({"verdict": "INCONCLUSIVE" if failed else "PASS",
                      "reasons": ["dropped_iterations must be zero."] if failed else []})
        elif "generator-info" in joined:
            emit({"workers": 4, "worker_pids": [11, 12, 13, 14], "worker_requests": [0, 0, 0, 0],
                  "started": 0, "completed": 0, "failed": 0, "php": "synthetic", "swoole": "synthetic"})
        elif "generator-observe.php" in joined or "LOAD_API=" in joined:
            pass
        else:
            raise RuntimeError("Unsupported synthetic compose exec")
    else:
        raise RuntimeError("Unsupported synthetic compose operation")
else:
    raise RuntimeError("Unsupported synthetic Docker operation")
