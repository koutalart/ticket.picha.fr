"""Vidéo de couverture du Salon Mayotte Recrute : 25 s en boucle, messages animés par séquence.

0,3 à 5,6 s   ouverture (logo, dates, lieu)
6,0 à 10,4 s  vendredi 23 octobre
10,8 à 15,2 s samedi 24 octobre
15,6 à 19,9 s espace candidats (bouton cliqué)
20,3 à 24,4 s espace exposants (bouton cliqué)
"""
import subprocess, sys
from PIL import Image, ImageDraw, ImageFont, ImageFilter

R, LOGO, FOND, OUT = sys.argv[1:5]
W, H, FPS, DUREE = 1280, 720, 30, 25
NAVY = (46, 55, 114)
JAUNE, ORANGE, ROUGE = (246, 195, 29), (232, 130, 27), (229, 54, 29)
BLANC = (255, 255, 255)


def mont(s, w=700):
    f = ImageFont.truetype(f"{R}/Montserrat.ttf", s); f.set_variation_by_axes([w]); return f


def clamp(x): return max(0.0, min(1.0, x))
def out_cubic(x): x = clamp(x); return 1 - (1 - x) ** 3
def in_cubic(x): x = clamp(x); return x ** 3
def out_expo(x): x = clamp(x); return 1 if x >= 1 else 1 - 2 ** (-10 * x)
def largeur(d, t, f, tr): return sum(d.textlength(c, font=f) for c in t) + tr * (len(t) - 1)


def degrade(w, h, c1, c2):
    g = Image.new("RGBA", (max(1, w), max(1, h))); d = ImageDraw.Draw(g)
    for i in range(g.width):
        t = i / max(1, g.width - 1); d.line([(i, 0), (i, h)], fill=tuple(int(a + (b - a) * t) for a, b in zip(c1, c2)) + (255,))
    return g


def mot(layer, t, texte, f, y, couleur, tr, debut, decal, duree):
    """Lettres qui montent depuis une ligne de coupe, en décalé."""
    d = ImageDraw.Draw(layer); b = d.textbbox((0, 0), texte, font=f); h = b[3] - b[1] + 10
    lw = int(largeur(d, texte, f, tr)) + 4
    boite = Image.new("RGBA", (lw, h), (0, 0, 0, 0)); db = ImageDraw.Draw(boite); x = 0
    for i, c in enumerate(texte):
        p = out_expo((t - debut - i * decal) / duree)
        if p > 0:
            db.text((x, (1 - p) * h - b[1]), c, font=f, fill=couleur[:3] + (int(couleur[3] * min(1, p * 1.6)),))
        x += d.textlength(c, font=f) + tr
    layer.alpha_composite(boite, (int((W - lw) / 2), int(y)))
    return h


def ligne(layer, t, texte, f, y, couleur, debut, tr0, tr1, duree=1.0):
    """Texte qui se resserre en apparaissant."""
    p = out_cubic((t - debut) / duree)
    if p <= 0: return
    d = ImageDraw.Draw(layer); tr = tr0 + (tr1 - tr0) * p
    b = d.textbbox((0, 0), texte, font=f); x = (W - largeur(d, texte, f, tr)) / 2
    for c in texte:
        d.text((x, y - b[1] + (1 - p) * 10), c, font=f, fill=couleur[:3] + (int(couleur[3] * p),)); x += d.textlength(c, font=f) + tr


def pastille(layer, t, texte, f, y, debut, duree=0.6):
    """Étiquette au dégradé du salon qui s'ouvre depuis le centre."""
    p = out_expo((t - debut) / duree)
    if p <= 0: return
    d = ImageDraw.Draw(layer); tw = largeur(d, texte, f, 3); bh = 44; bw = (tw + 48) * p
    g = degrade(int(bw), bh, JAUNE, ROUGE); m = Image.new("L", g.size, 0)
    ImageDraw.Draw(m).rounded_rectangle((0, 0, g.width - 1, bh - 1), radius=bh // 2, fill=255); g.putalpha(m)
    layer.alpha_composite(g, (int((W - g.width) / 2), int(y)))
    if p > 0.6:
        a = int(255 * clamp((p - 0.6) / 0.4)); b = d.textbbox((0, 0), texte, font=f); x = (W - tw) / 2
        for c in texte: d.text((x, y + (bh - (b[3] - b[1])) / 2 - b[1]), c, font=f, fill=BLANC + (a,)); x += d.textlength(c, font=f) + 3


def sortie(layer, t, debut, duree=0.5):
    p = in_cubic((t - debut) / duree)
    if p <= 0: return layer, 0
    layer.putalpha(layer.split()[3].point(lambda v: int(v * (1 - p)))); return layer, -int(16 * p)


def ombre(layer):
    a = layer.split()[3].filter(ImageFilter.GaussianBlur(10)).point(lambda v: int(v * 0.7))
    o = Image.new("RGBA", layer.size, (0, 0, 0, 0)); o.putalpha(a); return o


def poser(cadre, bloc, t, fin):
    bloc, dy = sortie(bloc, t, fin)
    cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))


FLECHE = [(0, 0), (0, 26), (7, 20), (12, 31), (16, 29), (11, 18), (20, 18)]


def bouton(layer, t, texte, y, debut, clic):
    """Bouton d'inscription : apparaît, la souris arrive et clique."""
    p = out_cubic((t - debut) / 0.5)
    if p <= 0: return
    f = mont(22, 800); d = ImageDraw.Draw(layer); tw = largeur(d, texte, f, 1); bw, bh = int(tw + 72), 62
    appui = 1 - 0.05 * max(0.0, 1 - abs(t - clic) / 0.12); k = (0.9 + 0.1 * p) * appui
    g = degrade(bw, bh, JAUNE, ROUGE); m = Image.new("L", g.size, 0)
    ImageDraw.Draw(m).rounded_rectangle((0, 0, bw - 1, bh - 1), radius=bh // 2, fill=255); g.putalpha(m)
    dg = ImageDraw.Draw(g); b = dg.textbbox((0, 0), texte, font=f); x = (bw - tw) / 2
    for c in texte: dg.text((x, (bh - (b[3] - b[1])) / 2 - b[1]), c, font=f, fill=BLANC + (255,)); x += dg.textlength(c, font=f) + 1
    g = g.resize((int(bw * k), int(bh * k)), Image.LANCZOS)
    g.putalpha(g.split()[3].point(lambda v: int(v * p)))
    cx0, cy0 = W / 2 + bw * 0.2, y + bh / 2 + 6
    layer.alpha_composite(g, (int(W / 2 - g.width / 2), int(y + bh / 2 - g.height / 2)))
    po = (t - clic) / 0.6
    if 0 < po < 1:
        r = 14 + 60 * out_cubic(po)
        ImageDraw.Draw(layer).ellipse((cx0 - r, cy0 - r, cx0 + r, cy0 + r), outline=BLANC + (int(200 * (1 - po)),), width=3)
    pa = out_cubic((t - (clic - 1.1)) / 1.0); pr = in_cubic((t - (clic + 0.7)) / 0.6)
    if pa > 0 and pr < 1:
        x = W * 0.80 + (cx0 - W * 0.80) * pa + 150 * pr; yy = H * 0.92 + (cy0 - H * 0.92) * pa + 80 * pr
        kk = 1.5 * (0.85 if abs(t - clic) < 0.1 else 1); pts = [(x + px * kk, yy + py * kk) for px, py in FLECHE]
        al = int(255 * min(1, pa * 3) * (1 - pr)); dd = ImageDraw.Draw(layer)
        dd.polygon([(a + 2, b + 3) for a, b in pts], fill=(0, 0, 0, al // 3))
        dd.polygon(pts, fill=BLANC + (al,), outline=(10, 10, 16, al))


voile = Image.new("L", (W, H), 0); ImageDraw.Draw(voile).ellipse((W * 0.12, H * 0.16, W * 0.88, H * 0.86), fill=255)
voile = voile.filter(ImageFilter.GaussianBlur(120))
teinte = Image.new("RGBA", (W, H), NAVY + (0,))

logo = Image.open(LOGO).convert("RGBA"); logo.thumbnail((620, 200), Image.LANCZOS)
f_sous = mont(19, 600); f_date = mont(40, 800); f_lieu = mont(22, 600)
f_kick = mont(20, 800); f_item = mont(30, 700); f_heure = mont(30, 800); f_titre = mont(46, 800); f_txt = mont(24, 600)


def programme(layer, t, jour, items, debut):
    pastille(layer, t, jour, f_kick, 214, debut)
    y = 290
    for i, (heure, texte) in enumerate(items):
        p = out_cubic((t - debut - 0.35 - i * 0.22) / 0.6)
        if p > 0:
            d = ImageDraw.Draw(layer); x0 = W / 2 - (largeur(d, heure, f_heure, 0) + 18 + largeur(d, texte, f_item, 0)) / 2 - (1 - p) * 30
            if heure:
                d.text((x0, y), heure, font=f_heure, fill=JAUNE + (int(255 * p),)); x0 += largeur(d, heure, f_heure, 0) + 18
            d.text((x0, y), texte, font=f_item, fill=BLANC + (int(255 * p),))
        y += 62


proc = subprocess.Popen(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", FOND,
                         "-f", "rawvideo", "-pix_fmt", "rgba", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
                         "-filter_complex", "[0:v][1:v]overlay=shortest=1,fps=24,format=yuv420p[o]", "-map", "[o]", "-an",
                         "-c:v", "libx264", "-preset", "veryslow", "-crf", "29", "-tune", "film", "-profile:v", "high", "-g", "48",
                         "-movflags", "+faststart", OUT], stdin=subprocess.PIPE)

SEQ = [(0.0, 5.6), (6.0, 10.4), (10.8, 15.2), (15.6, 19.9), (20.3, 24.4)]
MESURE = [4.5, 9.0, 13.8, 17.1, 21.75]


def contenu(t):
    """Bloc de texte de la séquence en cours (sans sortie), et l'heure de sortie."""
    bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    if t < 5.6:
        p = out_expo((t - 0.3) / 0.9)
        if p > 0:
            lg = logo.copy(); lg.putalpha(lg.split()[3].point(lambda x: int(x * p)))
            bloc.alpha_composite(lg, (int((W - lg.width) / 2), int(170 + (1 - p) * 24)))
        ligne(bloc, t, "SALON DE L'EMPLOI, DES MÉTIERS ET DE L'ATTRACTIVITÉ", f_sous, 170 + logo.height + 28, BLANC + (230,), 0.9, 8, 1.5)
        mot(bloc, t, "23 & 24 OCTOBRE 2026", f_date, 170 + logo.height + 80, JAUNE + (255,), 3, 1.4, 0.03, 0.6)
        ligne(bloc, t, "Kawéni, Mamoudzou   et en ligne", f_lieu, 170 + logo.height + 146, BLANC + (235,), 2.0, 6, 1.5)
        return bloc, 5.1
    if t < 10.4:
        programme(bloc, t, "VENDREDI 23 OCTOBRE", [("13h30", "Cérémonie d'ouverture"), ("14h30", "Reconstruire Mayotte : les emplois arrivent"), ("", "Job dating sans CV avec France Travail")], 6.0)
        return bloc, 9.9
    if t < 15.2:
        programme(bloc, t, "SAMEDI 24 OCTOBRE", [("9h00", "Atelier pitch face aux recruteurs"), ("13h30", "Job dating inversé"), ("14h30", "En direct avec la diaspora")], 10.8)
        return bloc, 14.7
    if t < 19.9:
        pastille(bloc, t, "CANDIDATS", f_kick, 196, 15.6)
        mot(bloc, t, "Rencontrez 40 recruteurs", f_titre, 262, BLANC + (255,), 2, 15.9, 0.02, 0.6)
        ligne(bloc, t, "Bar à CV, entretiens individuels, ateliers et conférences", f_txt, 338, BLANC + (230,), 16.4, 4, 0.5)
        bouton(bloc, t, "JE M'INSCRIS GRATUITEMENT", 400, 16.7, 18.3)
        return bloc, 19.4
    if t < 24.4:
        pastille(bloc, t, "EXPOSANTS", f_kick, 196, 20.3)
        mot(bloc, t, "1 000 talents à rencontrer", f_titre, 262, BLANC + (255,), 2, 20.6, 0.02, 0.6)
        ligne(bloc, t, "500 sur place, 500 en ligne, de l'île et de la diaspora", f_txt, 338, BLANC + (230,), 21.1, 4, 0.5)
        bouton(bloc, t, "RÉSERVER MON STAND", 400, 21.4, 22.9)
        return bloc, 23.9
    return None, None


# décalage vertical de chaque séquence pour que son texte, une fois complet, soit centré sur l'image
DECALAGE = []
for m in MESURE:
    bb = contenu(m)[0].split()[3].getbbox()
    DECALAGE.append(int(H / 2 - (bb[1] + bb[3]) / 2))

for n in range(FPS * DUREE):
    t = n / FPS
    cadre = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    k = max((out_cubic((t - a) / 0.6) * (1 - in_cubic((t - b + 0.5) / 0.7)) for a, b in SEQ), default=0)
    v = teinte.copy(); v.putalpha(voile.point(lambda x: int(x * (0.25 + 0.5 * k)))); cadre.alpha_composite(v)
    bloc, fin = contenu(t)
    if bloc is not None:
        i = next(j for j, (a, b) in enumerate(SEQ) if t < b)
        bloc, dy = sortie(bloc, t, fin)
        cadre.alpha_composite(ombre(bloc), (0, dy + DECALAGE[i])); cadre.alpha_composite(bloc, (0, dy + DECALAGE[i]))

    proc.stdin.write(cadre.tobytes())

proc.stdin.close(); proc.wait()
print("ok", OUT)
