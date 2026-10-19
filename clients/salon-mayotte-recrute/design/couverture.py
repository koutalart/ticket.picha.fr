"""Couverture et miniature du Salon Mayotte Recrute (1280x720)."""
import sys
from PIL import Image, ImageDraw, ImageFont, ImageFilter, ImageEnhance

R, V, OUT = sys.argv[1], sys.argv[2], sys.argv[3]
W, H = 1280, 720
NAVY, NAVY2 = (46, 55, 114), (82, 96, 167)


def mont(s, w):
    f = ImageFont.truetype(f"{R}/Montserrat.ttf", s); f.set_variation_by_axes([w]); return f


def degrade(w, h, c1, c2, horizontal=True):
    g = Image.new("RGB", (w, h)); d = ImageDraw.Draw(g)
    n = w if horizontal else h
    for i in range(n):
        t = i / max(1, n - 1); c = tuple(int(a + (b - a) * t) for a, b in zip(c1, c2))
        d.line([(i, 0), (i, h)] if horizontal else [(0, i), (w, i)], fill=c)
    return g


photo = Image.open(f"{V}/source/BG-MR-2-2.jpeg").convert("RGB")
k = max(W / photo.width, H / photo.height); photo = photo.resize((int(photo.width * k), int(photo.height * k)), Image.LANCZOS)
photo = photo.crop(((photo.width - W) // 2, (photo.height - H) // 2, (photo.width - W) // 2 + W, (photo.height - H) // 2 + H))
photo = ImageEnhance.Color(photo.filter(ImageFilter.GaussianBlur(1.2))).enhance(0.7)
voile = degrade(W, H, NAVY, NAVY2)
fond = Image.blend(photo, voile, 0.72)

img = fond.convert("RGBA"); d = ImageDraw.Draw(img)
logo = Image.open(f"{V}/logos/logo-salon-2026.png").convert("RGBA"); logo.thumbnail((960, 280), Image.LANCZOS)
titre = "SALON DE L'EMPLOI, DES MÉTIERS ET DE L'ATTRACTIVITÉ"
f = mont(26, 600); tw = d.textlength(titre, font=f)
haut = logo.height + 48 + 30; y0 = (H - haut) // 2
img.alpha_composite(logo, ((W - logo.width) // 2, y0))
d.text(((W - tw) / 2, y0 + logo.height + 48), titre, font=f, fill=(255, 255, 255, 235))
img.convert("RGB").save(OUT, quality=90)
print("ok", OUT)
