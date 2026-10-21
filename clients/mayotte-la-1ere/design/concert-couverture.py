import sys, glob
from PIL import Image, ImageDraw, ImageFont, ImageFilter
S, F = sys.argv[1], sys.argv[2]
I = S + "/img/"
W, H = 1600, 900
NAVY, GOLD, IVORY = (16, 32, 74), (201, 162, 74), (247, 242, 232)

def unb(s, w=800):
    f = ImageFont.truetype(F + "/Unbounded.ttf", s); f.set_variation_by_axes([w]); return f
def anton(s): return ImageFont.truetype(F + "/Anton.ttf", s)

img = Image.new("RGB", (W, H), IVORY)
# photos d'artistes, bandes verticales à droite
photos = sorted(glob.glob(I + "p10-*.jpg"))
x0, bw = 900, 140
for k, p in enumerate(photos):
    ph = Image.open(p).convert("RGB")
    r = max(bw / ph.width, H / ph.height)
    ph = ph.resize((int(ph.width * r) + 1, int(ph.height * r) + 1), Image.LANCZOS)
    cx = (ph.width - bw) // 2
    img.paste(ph.crop((cx, 0, cx + bw, H)), (x0 + k * bw, 0))
# fondu ivoire vers la gauche + filets or entre les bandes
fade = Image.new("L", (W, H), 0); d = ImageDraw.Draw(fade)
for x in range(880, 1060):
    d.line([(x, 0), (x, H)], fill=int(255 * (1 - (x - 880) / 180) ** 1.6))
d.rectangle((0, 0, 880, H), fill=255)
img = Image.composite(Image.new("RGB", (W, H), IVORY), img, fade)
d = ImageDraw.Draw(img)
for k in range(1, len(photos)):
    d.rectangle((x0 + k * bw - 2, 0, x0 + k * bw + 1, H), fill=GOLD)
# disque d'or en filigrane
disc = Image.new("RGBA", (W, H), (0, 0, 0, 0)); dd = ImageDraw.Draw(disc)
for r in range(120, 520, 14):
    dd.ellipse((-260 - r + 380, 640 - r, -260 + r + 380, 640 + r), outline=GOLD + (38,), width=2)
img.paste(disc, (0, 0), disc)

logo40 = Image.open(I + "p01-2-FormXob.a607754cdd96f8efd0a2886a50802346.png").convert("RGBA")
logo40.thumbnail((520, 200), Image.LANCZOS)
img.paste(logo40, (90, 90), logo40)

def texte(y, t, f, c, tr=0):
    x = 90
    for ch in t:
        d.text((x, y), ch, font=f, fill=c); x += d.textlength(ch, font=f) + tr
    b = d.textbbox((0, 0), t, font=f); return b[3]
y = 210
texte(y, "LE CONCERT", anton(104), NAVY, 2); y += 114
texte(y, "DES GÉNÉRATIONS", anton(104), NAVY, 2); y += 136
d.rectangle((90, y, 170, y + 5), fill=GOLD); y += 28
texte(y, "8 artistes, les chansons qui ont marqué Mayotte", unb(24, 500), NAVY); y += 40
texte(y, "revisitées en live", unb(24, 500), NAVY); y += 70
texte(y, "SAMEDI 14 NOVEMBRE 2026", unb(34, 800), NAVY, 1); y += 52
texte(y, "LOCAUX DE MAYOTTE LA 1ÈRE  |  20H15", unb(22, 600), NAVY, 2); y += 62
pill = unb(22, 800); t = "ENTRÉE GRATUITE SUR INSCRIPTION"
tw = d.textlength(t, font=pill) + 2 * len(t)
d.rounded_rectangle((90, y, 90 + tw + 56, y + 56), radius=28, fill=GOLD)
x = 118
for ch in t: d.text((x, y + 14), ch, font=pill, fill=NAVY); x += d.textlength(ch, font=pill) + 2
k = Image.open(I + "p01-1-FormXob.27b436012387ef92c3e70976064e8477.png").convert("RGBA")
k = k.crop(k.getbbox()); k = k.crop((0, 0, k.width, int(k.height * 0.36))); k.thumbnail((170, 70), Image.LANCZOS)
d.text((90, H - 72), "UNE PRODUCTION", font=unb(13, 600), fill=NAVY)
img.paste(k, (90 + int(d.textlength("UNE PRODUCTION", font=unb(13, 600))) + 22, H - 96), k)
img.save(S + "/concert-couverture.jpg", quality=90)
img.resize((1200, 675), Image.LANCZOS).save(S + "/concert-apercu.jpg", quality=85)
print("ok")
