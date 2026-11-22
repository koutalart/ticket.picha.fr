#!/usr/bin/env python3
"""Signale à IndexNow (Bing, Yandex, Seznam ; Bing alimente la recherche de ChatGPT) toutes les pages d'un site.

Usage : python3 scripts/seo/indexnow.py ticket.picha.fr [picha.fr ...]
Lit /sitemap.xml (et les sitemaps qu'il référence), puis envoie les URL par lots de 10 000.
La clé est servie à la racine de chaque site : https://<site>/10f9be7c90e0525e2027369925b6544f.txt
"""
import json
import re
import sys
import urllib.request

KEY = "10f9be7c90e0525e2027369925b6544f"
UA = {"User-Agent": "picha-indexnow/1.0"}


def get(url):
    with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=30) as r:
        return r.read().decode("utf-8", "replace")


def urls(host):
    vus, pages, file = set(), [], [f"https://{host}/sitemap.xml"]
    while file:
        sm = file.pop()
        if sm in vus:
            continue
        vus.add(sm)
        xml = get(sm)
        locs = re.findall(r"<loc>\s*([^<\s]+)\s*</loc>", xml)
        if "<sitemapindex" in xml:
            file += locs
        else:
            pages += [u for u in locs if u.startswith(f"https://{host}/")]
    return sorted(set(pages))


def ping(host):
    liste = urls(host)
    for i in range(0, len(liste), 10000):
        corps = json.dumps({"host": host, "key": KEY, "keyLocation": f"https://{host}/{KEY}.txt",
                            "urlList": liste[i:i + 10000]}).encode()
        req = urllib.request.Request("https://api.indexnow.org/indexnow", data=corps, method="POST",
                                     headers={**UA, "Content-Type": "application/json; charset=utf-8"})
        with urllib.request.urlopen(req, timeout=30) as r:
            print(f"{host} : {len(liste[i:i + 10000])} URL, réponse {r.status}")


if __name__ == "__main__":
    for h in sys.argv[1:] or ["ticket.picha.fr"]:
        ping(h)
