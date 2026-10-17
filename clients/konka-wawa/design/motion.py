import sys, subprocess
from PIL import Image, ImageDraw, ImageFont, ImageFilter

S, slug, FOND, OUT = sys.argv[1:5]
W, H, FPS, DUREE = 1280, 720, 30, 25

VILLES = {
    "marseille": ("MARSEILLE", "SAMEDI 7 NOVEMBRE", "FLORIDA PALACE", "162-164 bd Mireille Lauze, 13010 Marseille", (180, 124, 255)),
    "colmar": ("COLMAR", "SAMEDI 14 NOVEMBRE", "GOXWILLER", "1A rue du Vignoble, 67210 Goxwiller", (255, 79, 139)),
    "toulouse": ("TOULOUSE", "SAMEDI 21 NOVEMBRE", "SALLE AMIRA", "9 rue Jean Moulin, 31830 Plaisance-du-Touch", (92, 227, 110)),
    "lyon": ("LYON", "VENDREDI 27 NOVEMBRE", "LE GOLD RÉCEPTION", "7 route des Troques, 69630 Chaponost", (255, 90, 46)),
    "limoges": ("LIMOGES", "SAMEDI 28 NOVEMBRE", "PAVILLON DE BUXEROLLES", "20 rue Arthur Groussier, 87100 Limoges", (111, 211, 255)),
}
TOURNEE = [("MARSEILLE", "07.11"), ("COLMAR", "14.11"), ("TOULOUSE", "21.11"), ("LYON", "27.11"), ("LIMOGES", "28.11")]
VILLE, DATE, SALLE, ADRESSE, ACC = VILLES[slug]
ACC_CLAIR = tuple(int(c + (255 - c) * 0.35) for c in ACC)


def unb(s, w=900):
    f = ImageFont.truetype(f"{S}/Unbounded.ttf", s); f.set_variation_by_axes([w]); return f


def clamp(x): return max(0.0, min(1.0, x))
def out_cubic(x): x = clamp(x); return 1 - (1 - x) ** 3
def in_cubic(x): x = clamp(x); return x ** 3
def out_expo(x): x = clamp(x); return 1 if x >= 1 else 1 - 2 ** (-10 * x)


def largeur(d, t, f, tr): return sum(d.textlength(c, font=f) for c in t) + tr * (len(t) - 1)


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


def ligne(layer, t, texte, f, y, couleur, debut, tr0, tr1, duree=1.2):
    """Texte espacé qui se resserre en apparaissant."""
    p = out_cubic((t - debut) / duree)
    if p <= 0: return
    d = ImageDraw.Draw(layer); tr = tr0 + (tr1 - tr0) * p
    b = d.textbbox((0, 0), texte, font=f); x = (W - largeur(d, texte, f, tr)) / 2
    for c in texte:
        d.text((x, y - b[1] + (1 - p) * 10), c, font=f, fill=couleur[:3] + (int(couleur[3] * p),)); x += d.textlength(c, font=f) + tr


def filet(layer, t, y, debut, maxw, couleur, duree=0.9):
    p = out_expo((t - debut) / duree)
    if p <= 0: return
    w = maxw * p; ImageDraw.Draw(layer).rectangle(((W - w) / 2, y, (W + w) / 2, y + 2), fill=couleur)


def sortie(layer, t, debut, duree=0.7):
    """Le bloc s'efface en remontant légèrement."""
    p = in_cubic((t - debut) / duree)
    if p <= 0: return layer, 0
    a = layer.split()[3].point(lambda v: int(v * (1 - p)))
    layer.putalpha(a); return layer, -int(18 * p)


def ombre(layer):
    a = layer.split()[3].filter(ImageFilter.GaussianBlur(10)).point(lambda v: int(v * 0.7))
    o = Image.new("RGBA", layer.size, (0, 0, 0, 0)); o.putalpha(a); return o


# assombrissement doux au centre, plus marqué quand le titre est à l'écran
voile = Image.new("L", (W, H), 0); ImageDraw.Draw(voile).ellipse((W * 0.18, H * 0.22, W * 0.82, H * 0.80), fill=255)
voile = voile.filter(ImageFilter.GaussianBlur(120))

f_o_wawa = unb(46, 900); f_o_date = unb(40, 700); f_o_salle = unb(28, 500)
_t = ImageDraw.Draw(Image.new("RGBA", (10, 10))); _s = 124
while sum(_t.textlength(c, font=unb(_s, 900)) for c in VILLE) + 3 * (len(VILLE) - 1) > 900: _s -= 2
f_o_ville = unb(_s, 900)
f_kick = unb(17, 500); f_wawa = unb(70, 900); f_ville = unb(40, 700); f_info = unb(22, 500)
_ligne = "      ".join(f"{v} {dt}" for v, dt in TOURNEE); _st = 24
while sum(_t.textlength(c, font=unb(_st, 600)) for c in _ligne) + 2 * len(_ligne) > W - 90: _st -= 1
f_tour = unb(_st, 600); f_bar = unb(15, 400); f_mid = unb(56, 800); f_midk = unb(26, 600)
f_cta = unb(28, 800); f_site = unb(34, 800)

tmp = ImageDraw.Draw(Image.new("RGBA", (10, 10)))



def tournee(layer, t):
    """Les étapes de la tournée, l'étape du jour en couleur."""
    p_in = out_cubic((t - 8.3) / 0.8); p_out = in_cubic((t - 18.4) / 0.5)
    a = p_in * (1 - p_out)
    if a <= 0: return
    d = ImageDraw.Draw(layer); sep = "      "
    morceaux = []
    for i, (v, dt) in enumerate(TOURNEE):
        actif = v == VILLE
        morceaux.append((f"{v} {dt}", ACC_CLAIR if actif else (255, 255, 255), 255 if actif else 215))
        if i < len(TOURNEE) - 1: morceaux.append((sep, (255, 255, 255), 140))
    total = sum(largeur(d, m, f_tour, 2) + 2 for m, _, _ in morceaux)
    x = (W - total) / 2; y = H - 58 + (1 - p_in) * 8
    for m, coul, al in morceaux:
        for c in m:
            d.text((x, y), c, font=f_tour, fill=coul + (int(al * a),)); x += d.textlength(c, font=f_tour) + 2


CTA_A, CTA_B = "ACHÈTE TA PRÉVENTE EN LIGNE", "wawasalegy.com"
_d = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
WA = int(largeur(_d, CTA_A, f_cta, 2)) + 76; WB = int(largeur(_d, CTA_B, f_site, 1)) + 90; BH2 = 78
CY = 352
FLECHE = [(0, 0), (0, 26), (7, 20), (12, 31), (16, 29), (11, 18), (20, 18)]


def melange(c1, c2, p): return tuple(int(a + (b - a) * p) for a, b in zip(c1, c2))


def texte_centre(layer, txt, f, cx, cy, coul, tr):
    d = ImageDraw.Draw(layer); b = d.textbbox((0, 0), txt, font=f)
    x = cx - largeur(d, txt, f, tr) / 2; y = cy - (b[3] - b[1]) / 2 - b[1]
    for c in txt: d.text((x, y), c, font=f, fill=coul); x += d.textlength(c, font=f) + tr


def appel(cadre, t):
    """Bouton « prévente » : la souris clique, il devient wawasalegy.com."""
    if not 18.8 < t < 24.4: return
    p_in = out_cubic((t - 18.9) / 0.5)
    fa = out_cubic((t - 20.95) / 0.2); morph = out_cubic((t - 21.1) / 0.45); fb = out_cubic((t - 21.4) / 0.3)
    appui = 1 - 0.05 * max(0.0, 1 - abs(t - 20.85) / 0.12)
    echelle = (0.88 + 0.12 * p_in) * appui
    w = WA + (WB - WA) * morph
    bt = Image.new("RGBA", (int(WA + 40), BH2 + 40), (0, 0, 0, 0)); d = ImageDraw.Draw(bt)
    cx, cy = bt.width / 2, bt.height / 2
    fond = melange(ACC, (255, 255, 255), morph)
    d.rounded_rectangle((cx - w / 2, cy - BH2 / 2, cx + w / 2, cy + BH2 / 2), radius=BH2 // 2, fill=fond + (255,))
    if fa < 1: texte_centre(bt, CTA_A, f_cta, cx, cy, (14, 12, 22, int(255 * (1 - fa))), 2)
    if fb > 0: texte_centre(bt, CTA_B, f_site, cx, cy + (1 - fb) * 8, (14, 12, 22, int(255 * fb)), 1)
    if echelle != 1: bt = bt.resize((max(1, int(bt.width * echelle)), max(1, int(bt.height * echelle))), Image.LANCZOS)
    bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    halo = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(halo).rounded_rectangle((W / 2 - w / 2, CY - BH2 / 2, W / 2 + w / 2, CY + BH2 / 2), radius=BH2 // 2, fill=fond + (150,))
    bloc.alpha_composite(halo.filter(ImageFilter.GaussianBlur(22)))
    bloc.alpha_composite(bt, (int(W / 2 - bt.width / 2), int(CY - bt.height / 2)))
    # onde du clic
    cx0, cy0 = W / 2 + WA * 0.22, CY + 6
    po = (t - 20.85) / 0.6
    if 0 < po < 1:
        r = 14 + 70 * out_cubic(po)
        ImageDraw.Draw(bloc).ellipse((cx0 - r, cy0 - r, cx0 + r, cy0 + r), outline=(255, 255, 255, int(200 * (1 - po))), width=3)
    # souris : arrive, clique, repart
    pa = out_cubic((t - 19.5) / 1.1); pr = in_cubic((t - 21.7) / 0.7)
    if pa > 0 and pr < 1:
        x = W * 0.80 + (cx0 - W * 0.80) * pa + 160 * pr; y = H * 0.88 + (cy0 - H * 0.88) * pa + 90 * pr
        k = 1.5 * (0.85 if abs(t - 20.85) < 0.1 else 1)
        pts = [(x + px * k, y + py * k) for px, py in FLECHE]
        dd = ImageDraw.Draw(bloc); al = int(255 * min(1, pa * 3) * (1 - pr))
        dd.polygon([(a + 2, b + 3) for a, b in pts], fill=(0, 0, 0, al // 3))
        dd.polygon(pts, fill=(255, 255, 255, al), outline=(10, 10, 16, al))
    a = bloc.split()[3].point(lambda v: int(v * p_in)); bloc.putalpha(a)
    bloc, dy = sortie(bloc, t, 23.6, 0.6)
    cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))


# la ville en géant, en filigrane derrière le rappel du milieu
_fa = ImageFont.truetype(f"{S}/Anton.ttf", 560); _dg = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
_b = _dg.textbbox((0, 0), VILLE, font=_fa); _gw = int(largeur(_dg, VILLE, _fa, 14)) + 20
GEANT = Image.new("RGBA", (_gw, _b[3] - _b[1] + 20), (0, 0, 0, 0)); _gx = 10
for c in VILLE: ImageDraw.Draw(GEANT).text((_gx, 10 - _b[1]), c, font=_fa, fill=(255, 255, 255, 255)); _gx += _dg.textlength(c, font=_fa) + 14
GEANT_A = GEANT.split()[3]


def geant(cadre, t):
    a = out_cubic((t - 12.5) / 0.9) * (1 - in_cubic((t - 17.7) / 0.9))
    if a <= 0: return
    g = GEANT.copy(); g.putalpha(GEANT_A.point(lambda v: int(v * 0.2 * a)))
    x = (W - GEANT.width) / 2 + 110 - 220 * clamp((t - 12.5) / 6.1)
    cadre.alpha_composite(g, (int(x), int((H - GEANT.height) / 2 - 10)))


proc = subprocess.Popen(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", FOND,
                         "-f", "rawvideo", "-pix_fmt", "rgba", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
                         "-filter_complex", "[0:v][1:v]overlay=shortest=1,fps=24,format=yuv420p[o]", "-map", "[o]", "-an",
                         "-c:v", "libx264", "-preset", "veryslow", "-crf", "29", "-tune", "film", "-profile:v", "high", "-g", "48", "-movflags", "+faststart", OUT],
                        stdin=subprocess.PIPE)

for n in range(FPS * DUREE):
    t = n / FPS
    cadre = Image.new("RGBA", (W, H), (0, 0, 0, 0))

    # intensité du voile : fort pendant les deux blocs de texte, léger sinon
    k_a = out_cubic(t / 0.8) * (1 - in_cubic((t - 6.3) / 1.0))
    k_b = out_cubic((t - 12.8) / 0.8) * (1 - in_cubic((t - 17.8) / 0.8))
    k_c = out_cubic((t - 18.8) / 0.6) * (1 - in_cubic((t - 23.6) / 0.6))
    k = 0.25 + 0.45 * max(k_a, k_b, k_c)
    v = Image.new("RGBA", (W, H), (0, 0, 0, 0)); v.putalpha(voile.point(lambda a: int(a * k * 0.9)))
    cadre.alpha_composite(v)

    # bloc d'ouverture : la ville
    if t < 7.2:
        bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        vl = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        y = 214
        h = mot(bloc, t, "WAWA", f_o_wawa, y, (255, 255, 255, 255), 12, 0.3, 0.08, 0.7)
        y += h + 4
        h = mot(vl, t, VILLE, f_o_ville, y, ACC + (255,), 3, 0.6, 0.05, 0.8)
        y += h + 14
        h = mot(bloc, t, DATE, f_o_date, y, (255, 255, 255, 255), 4, 1.3, 0.025, 0.6)
        y += h + 6
        ligne(bloc, t, SALLE, f_o_salle, y, (255, 255, 255, 225), 1.7, 14, 5)
        g = vl.filter(ImageFilter.GaussianBlur(18)); lueur = Image.new("RGBA", (W, H), ACC + (0,))
        lueur.putalpha(g.split()[3].point(lambda a: min(255, int(a * 0.6))))
        bloc.alpha_composite(lueur); bloc.alpha_composite(vl)
        bloc, dy = sortie(bloc, t, 6.3)
        cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))

    # rappel en plein concert : la date
    geant(cadre, t)
    if 12.6 < t < 18.6:
        bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        y = 278
        ligne(bloc, t, f"WAWA AU GRAND COMPLET À {VILLE}", f_midk, y, ACC_CLAIR + (255,), 12.8, 14, 6)
        mot(bloc, t, DATE, f_mid, y + 46, (255, 255, 255, 255), 3, 13.1, 0.03, 0.6)
        bloc, dy = sortie(bloc, t, 17.8)
        cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))

    tournee(cadre, t)
    appel(cadre, t)
    proc.stdin.write(cadre.tobytes())

proc.stdin.close(); proc.wait()
print("ok", OUT)
