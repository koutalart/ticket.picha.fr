import sys, glob
from PIL import Image, ImageDraw, ImageFont, ImageFilter, ImageChops
S = sys.argv[1]; T = S + "/ftv/"; I = S + "/img/"
W, H = 1600, 900
INK, YEL, WHITE, BG = (255, 255, 255), (255, 186, 0), (255, 255, 255), (8, 8, 10)
FTV = "/Users/tkg976/Documents/Digit/DIGIT 2026/France Télévisions/trfontsftvbrowntt/FranceTVBrownTT-"
def font(s, w):
    return ImageFont.truetype(FTV + ("Black" if w >= 800 else "Bold" if w >= 600 else "Regular" if w >= 500 else "Light") + ".ttf", s)
img = Image.new("RGB", (W, H), BG)

# photos des artistes à droite, fondues dans le blanc
photos = sorted(glob.glob(I + "p10-*.jpg")); x0, bw = 1000, 120
strip = Image.new("RGB", (W, H), BG)
for k, p in enumerate(photos):
    ph = Image.open(p).convert("RGB"); r = max(bw / ph.width, H / ph.height)
    ph = ph.resize((int(ph.width * r) + 1, int(ph.height * r) + 1), Image.LANCZOS); cx = (ph.width - bw) // 2
    strip.paste(ph.crop((cx, 0, cx + bw, H)), (x0 + k * bw, 0))
mask = Image.new("L", (W, H), 0); dm = ImageDraw.Draw(mask)
for x in range(W):
    a = 0 if x < 990 else min(255, int(255 * ((x - 990) / 170) ** 1.3))
    dm.line([(x, 0), (x, H)], fill=a)
img = Image.composite(strip, img, mask)

# cercles jaunes en trame de points (comme l'affiche de la JPO)
def trame(cx, cy, R, alpha):
    lay = Image.new("RGBA", (W, H), (0, 0, 0, 0)); dl = ImageDraw.Draw(lay)
    dl.ellipse((cx - R, cy - R, cx + R, cy + R), fill=YEL + (int(alpha * 0.12),))
    for y in range(cy - R, cy + R, 14):
        for x in range(cx - R, cx + R, 14):
            if (x - cx) ** 2 + (y - cy) ** 2 < R * R:
                dl.ellipse((x - 2.5, y - 2.5, x + 2.5, y + 2.5), fill=YEL + (alpha,))
    return lay
for c in [(-40, 250, 150, 150), (980, 160, 190, 110), (120, 900, 230, 120), (1560, 860, 170, 120)]:
    img.paste(trame(*c), (0, 0), trame(*c))
d = ImageDraw.Draw(img)

# logos en tête : la chaîne | les 40 ans
logo = Image.open(T + "logo-mayotte-Dwjba.png").convert("RGBA"); logo = logo.crop(logo.getbbox())
logo = logo.resize((250, int(logo.height * 250 / logo.width)), Image.LANCZOS)
ban = Image.open(T + "banniere-cover-40-BcRcc.webp").convert("RGB").crop((400, 158, 1228, 392))
a = ban.convert("L").point(lambda v: 0 if v > 205 else (255 if v < 170 else int((205 - v) * 255 / 35)))
sat = ImageChops.subtract(ban.convert("HSV").split()[1], Image.new("L", ban.size, 25)).point(lambda v: 255 if v > 0 else 0)
ban.putalpha(ImageChops.lighter(a, sat)); ban = ban.resize((330, int(ban.height * 330 / ban.width)), Image.LANCZOS)
top = 70
def blanc(im, seuil, x_min=0):
    px = im.load()
    for y in range(im.height):
        for x in range(int(im.width * x_min), im.width):
            r, g, b, a = px[x, y]
            if a and (r + g + b) / 3 < seuil and not (r > 180 and b < 90):
                px[x, y] = (255, 255, 255, a)
    return im
logo = blanc(logo, 120); ban = blanc(ban, 95, 0.505)
img.paste(logo, (230, top + 18), logo)
d.line([(520, top), (520, top + 110)], fill=YEL, width=3)
img.paste(ban, (560, top + 10), ban)

# date surlignée, titre, bouton, accroche
cx = 520
df = font(40, 400); t1, t2 = "Samedi 14 novembre 2026", "20h15"
w1, w2, gap = d.textlength(t1, font=df), d.textlength(t2, font=df), 44
tw = w1 + gap + w2; xl = cx - tw / 2
d.rectangle((xl - 18, 262, xl + tw + 18, 318), fill=(46, 36, 8))
d.text((xl, 290), t1, font=df, fill=INK, anchor="lm")
d.ellipse((xl + w1 + gap / 2 - 7, 279, xl + w1 + gap / 2 + 7, 293), fill=YEL)
d.text((xl + w1 + gap, 290), t2, font=df, fill=INK, anchor="lm")
d.text((cx, 395), "LE CONCERT", font=font(92, 700), fill=INK, anchor="mm")
d.text((cx, 490), "DES GÉNÉRATIONS", font=font(92, 700), fill=YEL, anchor="mm")
bf = font(40, 600); b = "Entrée gratuite sur inscription"; bw2 = d.textlength(b, font=bf)
d.rounded_rectangle((cx - bw2 / 2 - 40, 572, cx + bw2 / 2 + 40, 650), radius=20, fill=YEL)
d.text((cx, 611), b, font=bf, fill=(10, 10, 10), anchor="mm")
d.text((cx, 700), "8 artistes revisitent les chansons qui ont marqué Mayotte", font=font(28, 400), fill=INK, anchor="mm")
d.text((cx, 742), "Dans les locaux de Mayotte La 1ère", font=font(24, 400), fill=INK, anchor="mm")
d.text((cx, 850), "Une production KULTUR'M", font=font(18, 500), fill=(170, 170, 170), anchor="mm")
img.save(S + "/concert-couverture-noir.jpg", quality=90)
img.resize((1200, 675), Image.LANCZOS).save(S + "/concert-apercu-noir.jpg", quality=85); print("ok")
