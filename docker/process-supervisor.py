#!/usr/bin/env python3
"""Run the web app's cooperating services as one signal-safe container."""

import os
import pwd
import signal
import subprocess
import sys
import time


STOP_REQUESTED = False


def request_stop(signum, _frame):
    global STOP_REQUESTED
    STOP_REQUESTED = True


def run_as(user_name):
    account = pwd.getpwnam(user_name)

    def demote():
        os.initgroups(account.pw_name, account.pw_gid)
        os.setgid(account.pw_gid)
        os.setuid(account.pw_uid)

    return demote


def start(name, command, *, user=None, environment=None):
    process_environment = os.environ.copy()
    if environment:
        process_environment.update(environment)
    process = subprocess.Popen(
        command,
        env=process_environment,
        preexec_fn=run_as(user) if user else None,
        start_new_session=True,
    )
    print(f"Started {name} (pid {process.pid}).", flush=True)
    return name, process


def signal_group(process, requested_signal):
    if process.poll() is not None:
        return
    try:
        os.killpg(process.pid, requested_signal)
    except ProcessLookupError:
        pass


def main():
    for handled_signal in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
        signal.signal(handled_signal, request_stop)

    processes = [
        start(
            "token provider",
            [
                "/usr/local/bin/deno",
                "run",
                "--allow-env",
                "--allow-net",
                "--allow-ffi=/opt/pot-provider/node_modules",
                "--allow-read=/opt/pot-provider/node_modules",
                "/opt/pot-provider/src/main.ts",
            ],
            user="www-data",
            environment={"HOME": "/tmp", "DENO_DIR": "/tmp/deno-cache"},
        ),
        start(
            "transcriber",
            ["/opt/transcriber-venv/bin/python", "/opt/transcriber/server.py"],
            user="www-data",
            environment={"HOME": "/tmp"},
        ),
        start("retention cleanup", ["/usr/local/bin/cleanup-loop"], user="www-data"),
        start("Apache", ["/usr/local/bin/apache2-foreground"]),
    ]

    exit_code = 0
    while not STOP_REQUESTED:
        for name, process in processes:
            result = process.poll()
            if result is not None:
                print(f"Required process {name} exited with status {result}.", file=sys.stderr, flush=True)
                exit_code = result if result != 0 else 1
                break
        else:
            time.sleep(0.5)
            continue
        break

    for _, process in processes:
        signal_group(process, signal.SIGTERM)

    deadline = time.monotonic() + 15
    while time.monotonic() < deadline and any(process.poll() is None for _, process in processes):
        time.sleep(0.2)
    for _, process in processes:
        signal_group(process, signal.SIGKILL)
    for _, process in processes:
        process.wait()
    return exit_code


if __name__ == "__main__":
    raise SystemExit(main())
