#!/usr/bin/env python3
"""Minimal localhost-only STARTTLS SMTP fixture for transport security tests."""
import argparse
import base64
import json
import signal
import socket
import ssl


def write_record(path, commands, message):
    with open(path, "w", encoding="utf-8") as output:
        json.dump({"commands": commands, "message": message}, output)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, required=True)
    parser.add_argument("--certificate")
    parser.add_argument("--key")
    parser.add_argument("--record", required=True)
    parser.add_argument("--no-starttls", action="store_true")
    arguments = parser.parse_args()
    commands, message = [], []
    login_username = ""
    login_stage = 0
    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind(("127.0.0.1", arguments.port))
    server.listen(2)
    server.settimeout(10)
    context = None
    if not arguments.no_starttls:
        if not arguments.certificate or not arguments.key:
            parser.error("--certificate and --key are required unless --no-starttls is used")
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(arguments.certificate, arguments.key)

    def finish(*_):
        write_record(arguments.record, commands, message)
        server.close()
        raise SystemExit(0)

    signal.signal(signal.SIGTERM, finish)
    try:
        while True:
            connection, _ = server.accept()
            connection.settimeout(5)
            stream = connection.makefile("rwb")
            stream.write(b"220 smtp-fixture ESMTP\r\n")
            stream.flush()
            data_mode = False
            while True:
                line = stream.readline()
                if not line:
                    break
                text = line.decode("utf-8", "replace").rstrip("\r\n")
                if data_mode:
                    if text == ".":
                        data_mode = False
                        stream.write(b"250 accepted\r\n")
                    else:
                        message.append(text)
                    stream.flush()
                    continue
                upper = text.upper()
                if login_stage == 1:
                    try:
                        login_username = base64.b64decode(text).decode()
                    except Exception:
                        login_username = "AUTH-DECODE-FAILED"
                    login_stage = 2
                    stream.write(b"334 UGFzc3dvcmQ6\r\n")
                elif login_stage == 2:
                    try:
                        password = base64.b64decode(text).decode()
                        commands.append(login_username + "|" + password)
                    except Exception:
                        commands.append("AUTH-DECODE-FAILED")
                    login_stage = 0
                    stream.write(b"235 authenticated\r\n")
                elif upper.startswith("EHLO"):
                    commands.append("EHLO")
                    if arguments.no_starttls:
                        stream.write(b"250-smtp-fixture\r\n250 AUTH PLAIN LOGIN\r\n")
                    else:
                        stream.write(b"250-smtp-fixture\r\n250-STARTTLS\r\n250 AUTH PLAIN LOGIN\r\n")
                elif upper == "STARTTLS":
                    commands.append("STARTTLS")
                    if arguments.no_starttls:
                        stream.write(b"454 TLS not available\r\n")
                        stream.flush()
                        continue
                    stream.write(b"220 ready\r\n")
                    stream.flush()
                    stream.close()
                    connection = context.wrap_socket(connection, server_side=True)
                    stream = connection.makefile("rwb")
                elif upper.startswith("AUTH PLAIN"):
                    commands.append("AUTH PLAIN")
                    payload = text[10:].strip()
                    if payload:
                        try:
                            _, username, password = base64.b64decode(payload).decode().split("\x00")
                            commands.append(username + "|" + password)
                        except Exception:
                            commands.append("AUTH-DECODE-FAILED")
                    stream.write(b"235 authenticated\r\n")
                elif upper == "AUTH LOGIN":
                    commands.append("AUTH LOGIN")
                    login_stage = 1
                    stream.write(b"334 VXNlcm5hbWU6\r\n")
                elif upper.startswith("MAIL FROM") or upper.startswith("RCPT TO"):
                    commands.append(upper.split(":", 1)[0])
                    stream.write(b"250 accepted\r\n")
                elif upper == "DATA":
                    commands.append("DATA")
                    data_mode = True
                    stream.write(b"354 send data\r\n")
                elif upper == "QUIT":
                    commands.append("QUIT")
                    stream.write(b"221 bye\r\n")
                    stream.flush()
                    break
                else:
                    commands.append(upper)
                    stream.write(b"250 accepted\r\n")
                stream.flush()
            stream.close()
            connection.close()
            if commands:
                write_record(arguments.record, commands, message)
    except (socket.timeout, OSError, ssl.SSLError) as error:
        commands.append("FIXTURE_ERROR:" + str(error))
        write_record(arguments.record, commands, message)
    finally:
        server.close()


if __name__ == "__main__":
    main()
