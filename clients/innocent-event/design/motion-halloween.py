import sys, subprocess
from PIL import Image, ImageDraw, ImageFont, ImageFilter

S, LOGO, FOND, OUT = sys.argv[1:5]
JOUR = sys.argv[5] if len(sys.argv) > 5 else ""
PRIX = sys.argv[6] if len(sys.argv) > 6 else "15"
ACCROCHES = {
    "": ("HALLOWEEN AU 5/5", "UNE SEULE NUIT", ""),
    "J-5": ("HALLOWEEN AU 5/5", "J-5", "SORS TON COSTUME"),
    "J-4": ("HALLOWEEN AU 5/5", "J-4", "LE D x JAH D ONE EN LIVE"),
    "J-3": ("HALLOWEEN AU 5/5", "J-3", "VIENS DÉGUISÉ(E)"),
    "J-2": ("HALLOWEEN AU 5/5", "J-2", "RAMÈNE TA TEAM"),
    "DEMAIN": ("HALLOWEEN AU 5/5", "DEMAIN SOIR", "DERNIÈRE LIGNE DROITE"),
    "CE-SOIR": ("HALLOWEEN AU 5/5", "CE SOIR", "DÈS 20:00 AU 5/5"),
}
W, H, FPS, DUREE = 1280, 720, 30, 25

TITRE, DATE, SALLE = "HALLOWEEN", "SAMEDI 31 OCTOBRE", "LE 5/5, MAMOUDZOU"
ACC = (247, 148, 29)
ROUGE = (220, 30, 30)
ACC_CLAIR = tuple(int(c + (255 - c) * 0.35) for c in ACC)
DJS = "DJ SWEETY      ASSKABOY      DJ BVNG'Z"
GEANT_TXT = "INNOCENT EVENT"


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
        if p > 0 and not c.isspace():
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

f_o_date = unb(36, 700); f_o_salle = unb(24, 500)
_t = ImageDraw.Draw(Image.new("RGBA", (10, 10))); _s = 124
while sum(_t.textlength(c, font=unb(_s, 900)) for c in TITRE) + 3 * (len(TITRE) - 1) > 760: _s -= 2
f_o_titre = unb(_s, 900)
_s = 96
while sum(_t.textlength(c, font=unb(_s, 900)) for c in "LE D x JAH D ONE") + 3 * 15 > 1060: _s -= 2
f_mid = unb(_s, 900); f_midk = unb(22, 600); f_djs = unb(27, 700)
f_cta = unb(28, 800); f_site = unb(34, 800)

# logo blanc d'Innocent Event (même dessin, couleur forcée en blanc)
_lg = Image.open(LOGO).convert("RGBA"); _lg = _lg.resize((int(_lg.width * 120 / _lg.height), 120), Image.LANCZOS)
LOGO_B = Image.new("RGBA", _lg.size, (255, 255, 255, 0)); LOGO_B.putalpha(_lg.split()[3])


def logo(layer, t, y, debut, duree=0.9):
    p = out_cubic((t - debut) / duree)
    if p <= 0: return 120
    k = 0.85 + 0.15 * p; lg = LOGO_B.resize((int(LOGO_B.width * k), int(LOGO_B.height * k)), Image.LANCZOS)
    lg.putalpha(lg.split()[3].point(lambda v: int(v * p)))
    layer.alpha_composite(lg, (int((W - lg.width) / 2), int(y + (1 - p) * 14 + (120 - lg.height) / 2)))
    return 120


tmp = ImageDraw.Draw(Image.new("RGBA", (10, 10)))



def djs(layer, t):
    """Les DJ de la soirée, en bas pendant le concert."""
    p_in = out_cubic((t - 8.3) / 0.8); p_out = in_cubic((t - 18.4) / 0.5)
    a = p_in * (1 - p_out)
    if a <= 0: return
    d = ImageDraw.Draw(layer); x = (W - largeur(d, DJS, f_djs, 3)) / 2; y = H - 66 + (1 - p_in) * 8
    for c in DJS:
        d.text((x, y), c, font=f_djs, fill=(255, 255, 255, int(225 * a))); x += d.textlength(c, font=f_djs) + 3


CTA_A, CTA_B = "ACHÈTE TA PRÉVENTE EN LIGNE", "innocent976.yt"
_d = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
WA = int(largeur(_d, CTA_A, f_cta, 2)) + 76; WB = int(largeur(_d, CTA_B, f_site, 1)) + 90; BH2 = 78
CY = 522
FLECHE = [(0, 0), (0, 26), (7, 20), (12, 31), (16, 29), (11, 18), (20, 18)]


def melange(c1, c2, p): return tuple(int(a + (b - a) * p) for a, b in zip(c1, c2))


def texte_centre(layer, txt, f, cx, cy, coul, tr):
    d = ImageDraw.Draw(layer); b = d.textbbox((0, 0), txt, font=f)
    x = cx - largeur(d, txt, f, tr) / 2; y = cy - (b[3] - b[1]) / 2 - b[1]
    for c in txt: d.text((x, y), c, font=f, fill=coul); x += d.textlength(c, font=f) + tr


def appel(cadre, t):
    """Bouton « prévente » : la souris clique, il devient innocent976.yt."""
    t = t - 0.5
    if not 18.8 < t < 24.0: return
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
    bloc, dy = sortie(bloc, t, 23.3, 0.6)
    cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))


# INNOCENT EVENT en géant, qui défile derrière le rappel du milieu
_fa = ImageFont.truetype(f"{S}/Anton.ttf", 560); _dg = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
_b = _dg.textbbox((0, 0), GEANT_TXT, font=_fa); _gw = int(largeur(_dg, GEANT_TXT, _fa, 14)) + 20
GEANT = Image.new("RGBA", (_gw, _b[3] - _b[1] + 20), (0, 0, 0, 0)); _gx = 10
for c in GEANT_TXT: ImageDraw.Draw(GEANT).text((_gx, 10 - _b[1]), c, font=_fa, fill=(255, 255, 255, 255)); _gx += _dg.textlength(c, font=_fa) + 14
GEANT_A = GEANT.split()[3]


def geant(cadre, t):
    a = out_cubic((t - 12.5) / 0.9) * (1 - in_cubic((t - 17.7) / 0.9))
    if a <= 0: return
    g = GEANT.copy(); g.putalpha(GEANT_A.point(lambda v: int(v * 0.2 * a)))
    x = (W - GEANT.width) / 2 + 160 - 320 * clamp((t - 12.5) / 6.1)
    cadre.alpha_composite(g, (int(x), int((H - GEANT.height) / 2 - 10)))


f_badge = unb(20, 800); f_prix = unb(150, 900); f_barre = unb(52, 700); f_rare = unb(24, 600); f_nuit = unb(84, 900); f_nuitk = unb(22, 600)


def offre(cadre, t):
    """Vente flash : badge, 15 € face à 20 € barré, 100 places seulement."""
    if not 18.6 < t < 24.6: return
    bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0)); d = ImageDraw.Draw(bloc)
    # badge VENTE FLASH qui pulse
    pb = out_cubic((t - 18.8) / 0.4)
    if pb > 0:
        txt = "VENTE FLASH" if PRIX == "15" else "PRÉVENTE EN LIGNE"; tw = largeur(d, txt, f_badge, 6); bw, bh = tw + 48, 44
        pulse = 1 + 0.04 * (0.5 + 0.5 * __import__("math").sin((t - 18.8) * 7))
        x0, y0 = W / 2 - bw * pulse / 2, 150 - bh * pulse / 2 + (1 - pb) * 10
        d.rounded_rectangle((x0, y0, x0 + bw * pulse, y0 + bh * pulse), radius=22, fill=ROUGE + (int(255 * pb),))
        b = d.textbbox((0, 0), txt, font=f_badge); x = W / 2 - tw / 2
        for c in txt: d.text((x, 150 - (b[3] - b[1]) / 2 - b[1] + (1 - pb) * 10), c, font=f_badge, fill=(255, 255, 255, int(255 * pb))); x += d.textlength(c, font=f_badge) + 6
    # prix : 15 € en grand, 20 € barré à côté
    pp = out_expo((t - 19.0) / 0.6)
    if pp > 0:
        prix = Image.new("RGBA", (W, H), (0, 0, 0, 0)); dp = ImageDraw.Draw(prix)
        a, bt = ("15 €", "20 €") if PRIX == "15" else ("20 €", "25 €")
        wa = dp.textlength(a, font=f_prix); wb = dp.textlength(bt, font=f_barre); gap = 34
        x = W / 2 - (wa + gap + wb) / 2; ba = dp.textbbox((0, 0), a, font=f_prix); y = 200
        dp.text((x, y - ba[1] + (1 - pp) * 30), a, font=f_prix, fill=(255, 255, 255, int(255 * pp)))
        bb = dp.textbbox((0, 0), bt, font=f_barre); xb = x + wa + gap; yb = y + (ba[3] - ba[1]) - (bb[3] - bb[1]) - 14
        pr = out_cubic((t - 19.5) / 0.5)
        if pr > 0:
            dp.text((xb, yb - bb[1]), bt, font=f_barre, fill=(200, 200, 200, int(200 * pr)))
            ls = out_cubic((t - 19.8) / 0.35)
            if ls > 0:
                ym = yb + (bb[3] - bb[1]) / 2; dp.line((xb - 6, ym + 6, xb - 6 + (wb + 12) * ls, ym - 6 - 0 * ls), fill=ROUGE + (255,), width=6)
        g = prix.filter(ImageFilter.GaussianBlur(18)); lueur = Image.new("RGBA", (W, H), ACC + (0,))
        lueur.putalpha(g.split()[3].point(lambda v: min(255, int(v * 0.5)))); bloc.alpha_composite(lueur); bloc.alpha_composite(prix)
    ligne(bloc, t, "100 PLACES SEULEMENT, ENSUITE 20 €" if PRIX == "15" else "25 € SUR PLACE, RÉSERVE EN LIGNE", f_rare, 408, ACC_CLAIR + (255,), 19.9, 12, 4, 0.9)
    bloc, dy = sortie(bloc, t, 23.8, 0.6)
    cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))


def nuit(cadre, t):
    """Accroche pendant le concert : une seule nuit, ou le compte à rebours du jour."""
    if not 8.4 < t < 12.4: return
    kick, titre, sous = ACCROCHES[JOUR]
    bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0)); vl = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d0 = ImageDraw.Draw(vl); taille = 84 if not JOUR else 170
    while largeur(d0, titre, unb(taille, 900), 3) > 980: taille -= 4
    f = unb(taille, 900); hb = d0.textbbox((0, 0), titre, font=f); ht = hb[3] - hb[1]
    y = 250 if not JOUR else 360 - ht / 2 - 40
    ligne(bloc, t, kick, f_nuitk, y, ACC_CLAIR + (255,), 8.6, 14, 6)
    mot(vl, t, titre, f, y + 40, (ACC if JOUR else (255, 255, 255)) + (255,), 3, 8.9, 0.05, 0.6)
    if sous:
        ligne(bloc, t, sous, unb(30, 700), y + 40 + ht + 34, (255, 255, 255, 255), 9.5, 12, 4, 0.9)
    g = vl.filter(ImageFilter.GaussianBlur(18)); lueur = Image.new("RGBA", (W, H), ROUGE + (0,))
    lueur.putalpha(g.split()[3].point(lambda v: min(255, int(v * 0.75)))); bloc.alpha_composite(lueur); bloc.alpha_composite(vl)
    bloc, dy = sortie(bloc, t, 11.6, 0.6)
    cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))


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
    k_c = out_cubic((t - 18.6) / 0.6) * (1 - in_cubic((t - 23.8) / 0.6))
    k_d = out_cubic((t - 8.4) / 0.6) * (1 - in_cubic((t - 11.6) / 0.6))
    k = 0.3 + 0.5 * max(k_a, k_b, k_c, k_d)
    v = Image.new("RGBA", (W, H), (0, 0, 0, 0)); v.putalpha(voile.point(lambda a: int(a * k * 0.9)))
    cadre.alpha_composite(v)

    # ouverture : logo, HALLOWEEN, date, salle
    if t < 7.2:
        bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        vl = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        y = 168
        y += logo(bloc, t, y, 0.2) + 14
        h = mot(vl, t, TITRE, f_o_titre, y, ACC + (255,), 3, 0.7, 0.05, 0.8)
        y += h + 14
        h = mot(bloc, t, DATE, f_o_date, y, (255, 255, 255, 255), 4, 1.4, 0.025, 0.6)
        y += h + 6
        ligne(bloc, t, SALLE, f_o_salle, y, (255, 255, 255, 225), 1.8, 14, 5)
        g = vl.filter(ImageFilter.GaussianBlur(18)); lueur = Image.new("RGBA", (W, H), ROUGE + (0,))
        lueur.putalpha(g.split()[3].point(lambda a: min(255, int(a * 0.8))))
        bloc.alpha_composite(lueur); bloc.alpha_composite(vl)
        bloc, dy = sortie(bloc, t, 6.3)
        cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))

    # rappel en plein concert : les artistes
    geant(cadre, t)
    if 12.6 < t < 18.6:
        bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        vl = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        y = 262
        ligne(bloc, t, "SAMEDI 31 OCTOBRE AU 5/5", f_midk, y, ACC_CLAIR + (255,), 12.8, 14, 6)
        mot(vl, t, "LE D x JAH D ONE", f_mid, y + 44, (255, 255, 255, 255), 3, 13.1, 0.03, 0.6)
        g = vl.filter(ImageFilter.GaussianBlur(16)); lueur = Image.new("RGBA", (W, H), ROUGE + (0,))
        lueur.putalpha(g.split()[3].point(lambda a: min(255, int(a * 0.6))))
        bloc.alpha_composite(lueur); bloc.alpha_composite(vl)
        bloc, dy = sortie(bloc, t, 17.8)
        cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))

    nuit(cadre, t)
    djs(cadre, t)
    offre(cadre, t)
    appel(cadre, t)
    proc.stdin.write(cadre.tobytes())

proc.stdin.close(); proc.wait()
print("ok", OUT)
