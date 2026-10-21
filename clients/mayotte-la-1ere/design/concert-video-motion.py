import sys, glob, math, subprocess
from PIL import Image, ImageDraw, ImageFont, ImageFilter, ImageEnhance

S, FOND, OUT = sys.argv[1], sys.argv[2], sys.argv[3]
I, T = S + "/img/", S + "/ftv/"
W, H, FPS, DUREE = 1280, 720, 24, 25
YEL, WHITE, BG = (255, 186, 0), (255, 255, 255), (8, 8, 10)
FTV = "/Users/tkg976/Documents/Digit/DIGIT 2026/France Télévisions/trfontsftvbrowntt/FranceTVBrownTT-"
def ftv(s, g="Bold"): return ImageFont.truetype(FTV + g + ".ttf", s)

def clamp(x): return max(0.0, min(1.0, x))
def out_cubic(x): x = clamp(x); return 1 - (1 - x) ** 3
def in_cubic(x): x = clamp(x); return x ** 3
def out_expo(x): x = clamp(x); return 1 if x >= 1 else 1 - 2 ** (-10 * x)
def largeur(d, t, f, tr): return sum(d.textlength(c, font=f) for c in t) + tr * (len(t) - 1)

def mot(layer, t, texte, f, y, couleur, tr, debut, decal, duree):
    d = ImageDraw.Draw(layer); b = d.textbbox((0, 0), texte, font=f); h = b[3] - b[1] + 10
    lw = int(largeur(d, texte, f, tr)) + 6
    boite = Image.new("RGBA", (lw, h), (0, 0, 0, 0)); db = ImageDraw.Draw(boite); x = 0
    for i, c in enumerate(texte):
        p = out_expo((t - debut - i * decal) / duree)
        if p > 0: db.text((x, (1 - p) * h - b[1]), c, font=f, fill=couleur + (int(255 * min(1, p * 1.6)),))
        x += d.textlength(c, font=f) + tr
    layer.alpha_composite(boite, (int((W - lw) / 2), int(y))); return h

def ligne(layer, t, texte, f, y, couleur, debut, tr0, tr1, duree=1.2):
    p = out_cubic((t - debut) / duree)
    if p <= 0: return
    d = ImageDraw.Draw(layer); tr = tr0 + (tr1 - tr0) * p
    b = d.textbbox((0, 0), texte, font=f); x = (W - largeur(d, texte, f, tr)) / 2
    for c in texte: d.text((x, y - b[1] + (1 - p) * 10), c, font=f, fill=couleur + (int(255 * p),)); x += d.textlength(c, font=f) + tr

def sortie(layer, t, debut, duree=0.7):
    p = in_cubic((t - debut) / duree)
    if p <= 0: return layer, 0
    layer.putalpha(layer.split()[3].point(lambda v: int(v * (1 - p)))); return layer, -int(18 * p)

def ombre(layer):
    o = Image.new("RGBA", layer.size, (0, 0, 0, 0)); o.putalpha(layer.split()[3].filter(ImageFilter.GaussianBlur(10)).point(lambda v: int(v * 0.8))); return o

def blanc(im, seuil, x_min=0):
    px = im.load()
    for y in range(im.height):
        for x in range(int(im.width * x_min), im.width):
            r, g, b, a = px[x, y]
            if a and (r + g + b) / 3 < seuil and not (r > 180 and b < 90): px[x, y] = (255, 255, 255, a)
    return im

# logos en version blanche (le point jaune et le « 40 » restent en couleur)
logo = Image.open(T + "logo-mayotte-Dwjba.png").convert("RGBA"); logo = logo.crop(logo.getbbox())
logo = blanc(logo.resize((190, int(logo.height * 190 / logo.width)), Image.LANCZOS), 120)
ban = Image.open(T + "banniere-cover-40-BcRcc.webp").convert("RGB").crop((400, 158, 1228, 392))
from PIL import ImageChops
a = ban.convert("L").point(lambda v: 0 if v > 205 else (255 if v < 170 else int((205 - v) * 255 / 35)))
sat = ImageChops.subtract(ban.convert("HSV").split()[1], Image.new("L", ban.size, 25)).point(lambda v: 255 if v > 0 else 0)
ban.putalpha(ImageChops.lighter(a, sat)); ban = blanc(ban.resize((250, int(ban.height * 250 / ban.width)), Image.LANCZOS), 95, 0.505)
ENTETE = Image.new("RGBA", (W, 110), (0, 0, 0, 0)); de = ImageDraw.Draw(ENTETE)
lx = (W - (190 + 50 + 250)) // 2
ENTETE.alpha_composite(logo, (lx, 55 - logo.height // 2 + 6)); de.line([(lx + 215, 18), (lx + 215, 92)], fill=YEL, width=3)
ENTETE.alpha_composite(ban, (lx + 240, 55 - ban.height // 2))

# bandes photo des artistes (dérive lente et périodique pour une boucle parfaite)
photos = sorted(glob.glob(I + "p10-*.jpg")); BW = W // len(photos); BANDES = []
for p in photos:
    ph = Image.open(p).convert("RGB"); r = max(BW / ph.width, (H + 120) / ph.height)
    BANDES.append(ph.resize((int(ph.width * r) + 1, int(ph.height * r) + 1), Image.LANCZOS))
voile = Image.new("L", (W, H), 0); ImageDraw.Draw(voile).ellipse((W * 0.12, H * 0.14, W * 0.88, H * 0.86), fill=255)
voile = voile.filter(ImageFilter.GaussianBlur(110))

def fond(t, eclat):
    base = int(255 * (0.55 - 0.30 * eclat))
    lay = Image.new("RGBA", (W, H), BG + (0,))
    lay.putalpha(voile.point(lambda v: min(255, base + int(v * (0.35 - 0.15 * eclat))))); return lay

def trame(cadre, t):
    lay = Image.new("RGBA", (W, H), (0, 0, 0, 0)); dl = ImageDraw.Draw(lay)
    for cx, cy, R, ph in [(-30, 160, 150, 0), (W + 20, 600, 170, 2.1)]:
        cy = cy + 18 * math.sin(2 * math.pi * t / DUREE + ph)
        for y in range(int(cy - R), int(cy + R), 13):
            for x in range(int(cx - R), int(cx + R), 13):
                if (x - cx) ** 2 + (y - cy) ** 2 < R * R: dl.ellipse((x - 2.2, y - 2.2, x + 2.2, y + 2.2), fill=YEL + (120,))
    cadre.alpha_composite(lay)

ARTISTES = ["Méli", "RH", "Chaldi", "Jah D One", "Kemaal & O.B.", "Annice", "Maliza"]
f_titre, f_date, f_kick, f_nom, f_cta, f_info = ftv(76), ftv(30, "Light"), ftv(22), ftv(96), ftv(32), ftv(26, "Light")
CTA_A, CTA_B = "Entrée gratuite sur inscription", "Inscriptions dès le 26 octobre"
FLECHE = [(0, 0), (0, 26), (7, 20), (12, 31), (16, 29), (11, 18), (20, 18)]
_d = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
WA, WB, BH2, CY = largeur(_d, CTA_A, f_cta, 0) + 80, largeur(_d, CTA_B, f_cta, 0) + 80, 70, 330

def bouton(cadre, t):
    if not 16.0 < t < 24.4: return
    p_in = out_cubic((t - 16.1) / 0.5)
    fa = out_cubic((t - 18.15) / 0.2); morph = out_cubic((t - 18.3) / 0.45); fb = out_cubic((t - 18.6) / 0.3)
    appui = 1 - 0.05 * max(0.0, 1 - abs(t - 18.05) / 0.12)
    w = WA + (WB - WA) * morph; fondc = tuple(int(a + (b - a) * morph) for a, b in zip(YEL, WHITE))
    bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0)); d = ImageDraw.Draw(bloc)
    s = (0.9 + 0.1 * p_in) * appui; ww, hh = w * s, BH2 * s
    halo = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(halo).rounded_rectangle((W / 2 - ww / 2, CY - hh / 2, W / 2 + ww / 2, CY + hh / 2), radius=int(hh / 2), fill=fondc + (140,))
    bloc.alpha_composite(halo.filter(ImageFilter.GaussianBlur(22)))
    d.rounded_rectangle((W / 2 - ww / 2, CY - hh / 2, W / 2 + ww / 2, CY + hh / 2), radius=int(hh / 2), fill=fondc + (255,))
    if fa < 1: d.text((W / 2, CY), CTA_A, font=f_cta, fill=(10, 10, 10, int(255 * (1 - fa))), anchor="mm")
    if fb > 0: d.text((W / 2, CY + (1 - fb) * 8), CTA_B, font=f_cta, fill=(10, 10, 10, int(255 * fb)), anchor="mm")
    ligne(bloc, t, "Samedi 14 novembre 2026, 20h15", f_info, CY + 70, WHITE, 16.6, 6, 1)
    ligne(bloc, t, "Dans les locaux de Mayotte La 1ère", f_info, CY + 108, WHITE, 16.8, 6, 1)
    ligne(bloc, t, "Avec Méli, RH, Chaldi, Jah D One, Kemaal & O.B., Annice, Maliza", ftv(24, "Light"), CY - 124, YEL, 16.4, 6, 1)
    cx0, cy0 = W / 2 + WA * 0.2, CY + 6; po = (t - 18.05) / 0.6
    if 0 < po < 1:
        r = 14 + 70 * out_cubic(po); d.ellipse((cx0 - r, cy0 - r, cx0 + r, cy0 + r), outline=(255, 255, 255, int(200 * (1 - po))), width=3)
    pa = out_cubic((t - 16.8) / 1.1); pr = in_cubic((t - 18.9) / 0.7)
    if pa > 0 and pr < 1:
        x = W * 0.80 + (cx0 - W * 0.80) * pa + 160 * pr; y = H * 0.9 + (cy0 - H * 0.9) * pa + 90 * pr
        k = 1.5 * (0.85 if abs(t - 18.05) < 0.1 else 1); pts = [(x + a * k, y + b * k) for a, b in FLECHE]
        al = int(255 * min(1, pa * 3) * (1 - pr))
        d.polygon([(a + 2, b + 3) for a, b in pts], fill=(0, 0, 0, al // 3)); d.polygon(pts, fill=(255, 255, 255, al), outline=(10, 10, 16, al))
    bloc.putalpha(bloc.split()[3].point(lambda v: int(v * p_in)))
    bloc, dy = sortie(bloc, t, 23.6, 0.6)
    cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))

proc = subprocess.Popen(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", FOND, "-f", "rawvideo", "-pix_fmt", "rgba", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
                         "-filter_complex", "[0:v][1:v]overlay=shortest=1,format=yuv420p[o]", "-map", "[o]", "-an", "-c:v", "libx264", "-preset", "veryslow", "-crf", "28", "-tune", "film", "-profile:v", "high",
                         "-g", "48", "-movflags", "+faststart", OUT], stdin=subprocess.PIPE)
for n in range(FPS * DUREE):
    t = n / FPS
    eclat = out_cubic((t - 4.6) / 0.5) * (1 - in_cubic((t - 15.0) / 0.5))
    cadre = fond(t, eclat); trame(cadre, t)
    if t < 5.0:
        bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        e = ENTETE.copy(); pe = out_cubic((t - 0.2) / 0.8); e.putalpha(e.split()[3].point(lambda v: int(v * pe)))
        bloc.alpha_composite(e, (0, 120 + int((1 - pe) * 12)))
        mot(bloc, t, "LE CONCERT", f_titre, 262, WHITE, 2, 0.6, 0.05, 0.7)
        mot(bloc, t, "DES GÉNÉRATIONS", f_titre, 350, YEL, 2, 0.95, 0.04, 0.7)
        ligne(bloc, t, "Samedi 14 novembre 2026, 20h15", f_date, 468, WHITE, 1.7, 8, 2)
        bloc, dy = sortie(bloc, t, 4.2, 0.6)
        cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))
    if 4.9 < t < 15.5:
        bloc = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        ligne(bloc, t, "SUR SCÈNE", f_kick, 250, YEL, 5.0, 14, 8)
        for nom, t0, t1 in [("Méli", 5.0, 7.2), ("Annice", 7.2, 9.4), ("Chaldi", 9.4, 11.4), ("Maliza", 11.4, 13.4), ("Jah D One", 13.4, 15.4)]:
            if t0 - 0.05 <= t < t1:
                nb = Image.new("RGBA", (W, H), (0, 0, 0, 0))
                mot(nb, t, nom, f_nom, 300, WHITE, 1, t0 + 0.1, 0.03, 0.45)
                nb, dy2 = sortie(nb, t, t1 - 0.35, 0.3)
                bloc.alpha_composite(nb, (0, dy2))
        bloc, dy = sortie(bloc, t, 15.0, 0.45)
        cadre.alpha_composite(ombre(bloc), (0, dy)); cadre.alpha_composite(bloc, (0, dy))
    bouton(cadre, t)
    proc.stdin.write(cadre.tobytes())
proc.stdin.close(); proc.wait(); print("ok", OUT)
