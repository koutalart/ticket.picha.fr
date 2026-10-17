"""Vidéo de couverture de la page organisateur : toute la tournée, 25 s en boucle.

python tournee.py <ressources> <fond-tournee.mp4> <sortie.mp4>
De 0 à 2 s WAWA et TOURNÉE 2026, puis une ville toutes les 3 s (2 à 17 s), puis l'appel à acheter en ligne.
Première campagne : d'autres villes s'ajouteront.
"""
import sys, subprocess
from PIL import Image, ImageDraw, ImageFont, ImageFilter

S, FOND, OUT = sys.argv[1:4]
W, H, FPS, DUREE = 1280, 720, 30, 25

ETAPES = [
    ("MARSEILLE", "SAMEDI 7 NOVEMBRE", "FLORIDA PALACE", "07.11", (180, 124, 255)),
    ("COLMAR", "SAMEDI 14 NOVEMBRE", "GOXWILLER", "14.11", (255, 79, 139)),
    ("TOULOUSE", "SAMEDI 21 NOVEMBRE", "SALLE AMIRA", "21.11", (92, 227, 110)),
    ("LYON", "VENDREDI 27 NOVEMBRE", "LE GOLD RÉCEPTION", "27.11", (255, 90, 46)),
    ("LIMOGES", "SAMEDI 28 NOVEMBRE", "PAVILLON DE BUXEROLLES", "28.11", (111, 211, 255)),
]
OR = (255, 210, 63)
DEBUT_VILLES, PAS = 2.0, 3.0


def unb(s, w=900):
    f = ImageFont.truetype(f"{S}/Unbounded.ttf", s); f.set_variation_by_axes([w]); return f


def clamp(x): return max(0.0, min(1.0, x))
def out_cubic(x): x = clamp(x); return 1 - (1 - x) ** 3
def in_cubic(x): x = clamp(x); return x ** 3
def out_expo(x): x = clamp(x); return 1 if x >= 1 else 1 - 2 ** (-10 * x)
def clair(c, k=0.35): return tuple(int(v + (255 - v) * k) for v in c)
def melange(c1, c2, p): return tuple(int(a + (b - a) * p) for a, b in zip(c1, c2))


def largeur(d, t, f, tr): return sum(d.textlength(c, font=f) for c in t) + tr * (len(t) - 1)


def ajuste(texte, taille, poids, tr, maxw):
    d = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
    while largeur(d, texte, unb(taille, poids), tr) > maxw: taille -= 2
    return unb(taille, poids)


def mot(layer, t, texte, f, y, couleur, tr, debut, decal, duree):
    """Lettres qui montent depuis une ligne de coupe, en décalé."""
    d = ImageDraw.Draw(layer)
    b = d.textbbox((0, 0), texte, font=f); h = b[3] - b[1] + 8
    lw = int(largeur(d, texte, f, tr)) + 4
    boite = Image.new("RGBA", (lw, h), (0, 0, 0, 0)); db = ImageDraw.Draw(boite)
    x = 0
    for i, c in enumerate(texte):
        p = out_expo((t - debut - i * decal) / duree)
        if p > 0:
            db.text((x, (1 - p) * h - b[1]), c, font=f, fill=couleur[:3] + (int(couleur[3] * min(1, p * 1.6)),))
        x += d.textlength(c, font=f) + tr
    layer.alpha_composite(boite, (int((W - lw) / 2), int(y)))
    return h


def ligne(layer, t, texte, f, y, couleur, debut, tr0, tr1, duree=1.0):
    """Texte espacé qui se resserre en apparaissant."""
    p = out_cubic((t - debut) / duree)
    if p <= 0: return
    d = ImageDraw.Draw(layer); tr = tr0 + (tr1 - tr0) * p
    b = d.textbbox((0, 0), texte, font=f); x = (W - largeur(d, texte, f, tr)) / 2
    for c in texte:
        d.text((x, y - b[1] + (1 - p) * 10), c, font=f, fill=couleur[:3] + (int(couleur[3] * p),)); x += d.textlength(c, font=f) + tr


def sortie(layer, t, debut, duree=0.45):
    p = in_cubic((t - debut) / duree)
    if p <= 0: return layer, 0
    layer.putalpha(layer.split()[3].point(lambda v: int(v * (1 - p)))); return layer, -int(18 * p)


def ombre(layer):
    a = layer.split()[3].filter(ImageFilter.GaussianBlur(10)).point(lambda v: int(v * 0.7))
    o = Image.new("RGBA", layer.size, (0, 0, 0, 0)); o.putalpha(a); return o


def lueur(layer, couleur, k=0.6):
    g = layer.filter(ImageFilter.GaussianBlur(18)); l = Image.new("RGBA", (W, H), couleur + (0,))
    l.putalpha(g.split()[3].point(lambda a: min(255, int(a * k)))); return l


def poser(cadre, bloc, t, debut_sortie, duree=0.45):
    bloc, dy = sortie(bloc, t, debut_sortie, duree)
    cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))


voile = Image.new("L", (W, H), 0); ImageDraw.Draw(voile).ellipse((W * 0.14, H * 0.18, W * 0.86, H * 0.84), fill=255)
voile = voile.filter(ImageFilter.GaussianBlur(120))

f_kick = unb(26, 600); f_wawa = unb(132, 900); f_num = unb(24, 600)
f_date = unb(40, 700); f_salle = unb(28, 500); f_titre = unb(76, 900)
f_cta = unb(30, 800); f_site = unb(36, 800)
f_villes = [ajuste(v, 150, 900, 4, 1040) for v, *_ in ETAPES]
_d = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
SUITE = "ET D'AUTRES VILLES BIENTÔT"
SEP = "     "
_ligne = SEP.join([f"{v} {n}" for v, _, _, n, _ in ETAPES] + [SUITE]); _st = 26
while largeur(_d, _ligne, unb(_st, 600), 2) > W - 90: _st -= 1
f_tour = unb(_st, 600)

CTA_A, CTA_B = "ACHÈTE TA PLACE EN LIGNE", "wawasalegy.com"
WA = int(largeur(_d, CTA_A, f_cta, 2)) + 96; WB = int(largeur(_d, CTA_B, f_site, 1)) + 96; BH = 84; CY = 420
FLECHE = [(0, 0), (0, 26), (7, 20), (12, 31), (16, 29), (11, 18), (20, 18)]


def texte_centre(layer, txt, f, cx, cy, coul, tr):
    d = ImageDraw.Draw(layer); b = d.textbbox((0, 0), txt, font=f)
    x = cx - largeur(d, txt, f, tr) / 2; y = cy - (b[3] - b[1]) / 2 - b[1]
    for c in txt: d.text((x, y), c, font=f, fill=coul); x += d.textlength(c, font=f) + tr


def ouverture(cadre, t):
    if t >= 2.0: return
    bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ligne(bloc, t, "TOURNÉE 2026", f_kick, 228, OR + (255,), 0.1, 18, 12, 0.8)
    v = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    mot(v, t, "WAWA", f_wawa, 272, (255, 255, 255, 255), 18, 0.25, 0.08, 0.6)
    ligne(bloc, t, "LES PREMIÈRES DATES", f_kick, 440, (255, 255, 255, 230), 0.6, 14, 8, 0.8)
    bloc.alpha_composite(lueur(v, (255, 255, 255), 0.35)); bloc.alpha_composite(v)
    poser(cadre, bloc, t, 1.55)


def etape(cadre, t):
    i = int((t - DEBUT_VILLES) // PAS)
    if not 0 <= i < len(ETAPES): return
    ville, date, salle, _, acc = ETAPES[i]
    t0 = DEBUT_VILLES + i * PAS
    bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0)); v = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ligne(bloc, t, "WAWA EN CONCERT", f_num, 196, clair(acc) + (255,), t0 + 0.05, 16, 8, 0.6)
    h = mot(v, t, ville, f_villes[i], 240, acc + (255,), 4, t0 + 0.12, 0.035, 0.55)
    mot(bloc, t, date, f_date, 240 + h + 18, (255, 255, 255, 255), 4, t0 + 0.45, 0.018, 0.5)
    ligne(bloc, t, salle, f_salle, 240 + h + 80, (255, 255, 255, 225), t0 + 0.7, 14, 5, 0.6)
    bloc.alpha_composite(lueur(v, acc)); bloc.alpha_composite(v)
    poser(cadre, bloc, t, t0 + PAS - 0.45)


def suivi(cadre, t):
    """Les cinq dates en bas, l'étape à l'écran en couleur."""
    a = out_cubic((t - 2.0) / 0.5) * (1 - in_cubic((t - 16.6) / 0.4))
    if a <= 0: return
    i = int((t - DEBUT_VILLES) // PAS)
    d = ImageDraw.Draw(cadre); morceaux = []
    for k, (v, _, _, n, acc) in enumerate(ETAPES):
        morceaux.append((f"{v} {n}", clair(acc) if k == i else (255, 255, 255), 255 if k == i else 170))
        morceaux.append((SEP, (255, 255, 255), 0))
    morceaux.append((SUITE, OR, 230))
    x = (W - sum(largeur(d, m, f_tour, 2) + 2 for m, _, _ in morceaux)) / 2; y = H - 60
    for m, coul, al in morceaux:
        for c in m: d.text((x, y), c, font=f_tour, fill=coul + (int(al * a),)); x += d.textlength(c, font=f_tour) + 2


def appel(cadre, t):
    """Billets en vente : la souris clique le bouton, il devient wawasalegy.com."""
    if not 17.0 < t < 24.4: return
    bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ligne(bloc, t, "PREMIÈRES DATES EN VENTE", f_kick, 214, OR + (255,), 17.15, 16, 8, 0.8)
    mot(bloc, t, "RÉSERVE TA PLACE", f_titre, 262, (255, 255, 255, 255), 4, 17.35, 0.03, 0.6)
    p_in = out_cubic((t - 18.3) / 0.5)
    if p_in > 0:
        fa = out_cubic((t - 20.35) / 0.2); morph = out_cubic((t - 20.5) / 0.45); fb = out_cubic((t - 20.8) / 0.3)
        appui = 1 - 0.05 * max(0.0, 1 - abs(t - 20.25) / 0.12)
        echelle = (0.88 + 0.12 * p_in) * appui
        w = WA + (WB - WA) * morph; fond = melange(OR, (255, 255, 255), morph)
        bt = Image.new("RGBA", (WA + 40, BH + 40), (0, 0, 0, 0)); d = ImageDraw.Draw(bt)
        cx, cy = bt.width / 2, bt.height / 2
        d.rounded_rectangle((cx - w / 2, cy - BH / 2, cx + w / 2, cy + BH / 2), radius=BH // 2, fill=fond + (255,))
        if fa < 1: texte_centre(bt, CTA_A, f_cta, cx, cy, (14, 12, 22, int(255 * (1 - fa))), 2)
        if fb > 0: texte_centre(bt, CTA_B, f_site, cx, cy + (1 - fb) * 8, (14, 12, 22, int(255 * fb)), 1)
        bt = bt.resize((max(1, int(bt.width * echelle)), max(1, int(bt.height * echelle))), Image.LANCZOS)
        halo = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        ImageDraw.Draw(halo).rounded_rectangle((W / 2 - w / 2, CY - BH / 2, W / 2 + w / 2, CY + BH / 2), radius=BH // 2, fill=fond + (150,))
        couche = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        couche.alpha_composite(halo.filter(ImageFilter.GaussianBlur(22)))
        couche.alpha_composite(bt, (int(W / 2 - bt.width / 2), int(CY - bt.height / 2)))
        cx0, cy0 = W / 2 + WA * 0.22, CY + 8
        po = (t - 20.25) / 0.6
        if 0 < po < 1:
            r = 14 + 70 * out_cubic(po)
            ImageDraw.Draw(couche).ellipse((cx0 - r, cy0 - r, cx0 + r, cy0 + r), outline=(255, 255, 255, int(200 * (1 - po))), width=3)
        pa = out_cubic((t - 19.0) / 1.0); pr = in_cubic((t - 21.2) / 0.7)
        if pa > 0 and pr < 1:
            x = W * 0.80 + (cx0 - W * 0.80) * pa + 160 * pr; y = H * 0.9 + (cy0 - H * 0.9) * pa + 90 * pr
            k = 1.6 * (0.85 if abs(t - 20.25) < 0.1 else 1)
            pts = [(x + px * k, y + py * k) for px, py in FLECHE]
            dd = ImageDraw.Draw(couche); al = int(255 * min(1, pa * 3) * (1 - pr))
            dd.polygon([(a + 2, b + 3) for a, b in pts], fill=(0, 0, 0, al // 3))
            dd.polygon(pts, fill=(255, 255, 255, al), outline=(10, 10, 16, al))
        couche.putalpha(couche.split()[3].point(lambda v: int(v * p_in)))
        bloc.alpha_composite(couche)
    poser(cadre, bloc, t, 23.7, 0.6)


proc = subprocess.Popen(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", FOND,
                         "-f", "rawvideo", "-pix_fmt", "rgba", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
                         "-filter_complex", "[0:v][1:v]overlay=shortest=1,fps=24,format=yuv420p[o]", "-map", "[o]", "-an",
                         "-c:v", "libx264", "-preset", "veryslow", "-crf", "29", "-tune", "film", "-profile:v", "high", "-g", "48", "-movflags", "+faststart", OUT],
                        stdin=subprocess.PIPE)

for n in range(FPS * DUREE):
    t = n / FPS
    cadre = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    actif = 1.0 if t < 24.0 else 1 - in_cubic((t - 24.0) / 0.5)
    k = 0.25 + 0.45 * actif * out_cubic(t / 0.4)
    v = Image.new("RGBA", (W, H), (0, 0, 0, 0)); v.putalpha(voile.point(lambda a: int(a * k * 0.9)))
    cadre.alpha_composite(v)
    ouverture(cadre, t)
    etape(cadre, t)
    suivi(cadre, t)
    appel(cadre, t)
    proc.stdin.write(cadre.tobytes())

proc.stdin.close(); proc.wait()
print("ok", OUT)
