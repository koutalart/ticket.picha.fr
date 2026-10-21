#!/usr/bin/env python3
"""Relais d'impression PICHA Ticket.

Tourne sur un ordinateur du lieu, sur le meme reseau que l'imprimante Zebra.
Il recupere les billets en attente sur PICHA Ticket et les envoie a la Zebra
(port 9100). Python 3 standard uniquement, rien a installer.
"""

import argparse
import json
import logging
import os
import socket
import sys
import time
import urllib.error
import urllib.request

DEFAULT_URL = "https://ticket.picha.fr/api"
DEFAULT_PORT = 9100
PRINTER_TIMEOUT = 5
HTTP_TIMEOUT = 15
MAX_BACKOFF = 10.0
USER_AGENT = "picha-print-relay/1.0"

TEST_LABEL = (
    "^XA^CI28"
    "^FO40,40^A0N,40,40^FDPICHA Ticket^FS"
    "^FO40,100^A0N,30,30^FDTest du relais d'impression^FS"
    "^FO40,150^A0N,26,26^FD{when}^FS"
    "^XZ"
)

log = logging.getLogger("picha_relay")


class ApiError(Exception):
    pass


class InvalidToken(Exception):
    pass


def parse_printer(value):
    host, sep, port = value.strip().rpartition(":")
    if not sep:
        return value.strip(), DEFAULT_PORT
    if not port.isdigit():
        raise argparse.ArgumentTypeError("port invalide : %s" % value)
    return host, int(port)


def send_to_printer(host, port, zpl):
    with socket.create_connection((host, port), timeout=PRINTER_TIMEOUT) as sock:
        sock.settimeout(PRINTER_TIMEOUT)
        sock.sendall(zpl.encode("utf-8"))


def api_request(base_url, token, method, path, body=None):
    data = json.dumps(body).encode("utf-8") if body is not None else None
    request = urllib.request.Request(
        base_url.rstrip("/") + path,
        data=data,
        method=method,
        headers={
            "Authorization": "Bearer " + token,
            "Accept": "application/json",
            "Content-Type": "application/json",
            "User-Agent": USER_AGENT,
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=HTTP_TIMEOUT) as response:
            raw = response.read()
    except urllib.error.HTTPError as error:
        if error.code == 401:
            raise InvalidToken()
        raise ApiError("le serveur a repondu %s" % error.code)
    except (urllib.error.URLError, socket.timeout, OSError) as error:
        raise ApiError("serveur injoignable (%s)" % getattr(error, "reason", error))
    if not raw:
        return {}
    try:
        return json.loads(raw.decode("utf-8"))
    except ValueError:
        raise ApiError("reponse illisible du serveur")


def ack(base_url, token, job_id, status, error=None):
    body = {"status": status}
    if error:
        body["error"] = str(error)[:900]
    api_request(base_url, token, "POST", "/print-relay/jobs/%d/ack" % job_id, body)


def process_job(args, host, port, job):
    job_id = int(job["id"])
    label = job.get("attendee_public_id") or "#%d" % job_id
    try:
        send_to_printer(host, port, job["zpl"])
    except (OSError, socket.timeout) as error:
        log.error("Echec impression billet %s sur %s:%d : %s", label, host, port, error)
        ack(args.url, args.token, job_id, "FAILED", "%s:%d %s" % (host, port, error))
        return False
    log.info("Billet %s imprime sur %s:%d", label, host, port)
    ack(args.url, args.token, job_id, "SENT")
    return True


def run(args, host, port):
    log.info("Relais demarre : serveur %s, imprimante %s:%d", args.url, host, port)
    backoff = args.interval
    printer_down = False
    while True:
        try:
            payload = api_request(args.url, args.token, "GET", "/print-relay/jobs")
            jobs = payload.get("data") or []
            backoff = args.interval
            for job in jobs:
                ok = process_job(args, host, port, job)
                if not ok:
                    printer_down = True
                    break
                printer_down = False
            if printer_down:
                time.sleep(min(MAX_BACKOFF, 5.0))
            elif not jobs:
                time.sleep(args.interval)
        except InvalidToken:
            log.error("Jeton refuse par le serveur. Generez un nouveau jeton dans les reglages du kiosque.")
            time.sleep(MAX_BACKOFF)
        except ApiError as error:
            backoff = min(MAX_BACKOFF, max(args.interval, backoff * 2))
            log.warning("Probleme reseau : %s. Nouvel essai dans %.0f s", error, backoff)
            time.sleep(backoff)
        except Exception as error:  # noqa: BLE001  never crash the venue relay
            backoff = min(MAX_BACKOFF, max(args.interval, backoff * 2))
            log.exception("Erreur inattendue : %s. Nouvel essai dans %.0f s", error, backoff)
            time.sleep(backoff)


def print_test_label(host, port):
    zpl = TEST_LABEL.format(when=time.strftime("%d/%m/%Y %H:%M:%S"))
    try:
        send_to_printer(host, port, zpl)
    except (OSError, socket.timeout) as error:
        log.error("Imprimante %s:%d injoignable : %s", host, port, error)
        return 1
    log.info("Etiquette de test envoyee a %s:%d", host, port)
    return 0


def main(argv=None):
    parser = argparse.ArgumentParser(description="Relais d'impression PICHA Ticket vers une Zebra.")
    parser.add_argument("--url", default=DEFAULT_URL, help="adresse de l'API (defaut : %(default)s)")
    parser.add_argument("--token", default=os.environ.get("PICHA_RELAY_TOKEN"), help="jeton du relais (ou variable PICHA_RELAY_TOKEN)")
    parser.add_argument("--printer", required=True, type=parse_printer, help="IP de la Zebra, ex. 192.168.1.21 ou 192.168.1.21:9100")
    parser.add_argument("--interval", type=float, default=1.5, help="secondes entre deux verifications (defaut : %(default)s)")
    parser.add_argument("--test", action="store_true", help="imprime une etiquette de test puis quitte")
    parser.add_argument("--verbose", action="store_true", help="journal detaille")
    args = parser.parse_args(argv)

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(message)s",
        datefmt="%H:%M:%S",
    )
    args.interval = max(0.5, args.interval)
    host, port = args.printer

    if args.test:
        return print_test_label(host, port)

    if not args.token:
        parser.error("jeton manquant : ajoutez --token ou la variable PICHA_RELAY_TOKEN")

    try:
        run(args, host, port)
    except KeyboardInterrupt:
        log.info("Relais arrete.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
