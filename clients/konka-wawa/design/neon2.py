import sys
from PIL import Image, ImageDraw, ImageFont, ImageFilter, ImageEnhance
S, K = sys.argv[1], sys.argv[2]
W, H = 1280, 720
villes = [("marseille","MARSEILLE","SAMEDI 7 NOVEMBRE","FLORIDA PALACE",(180,124,255)),
          ("colmar","COLMAR","SAMEDI 14 NOVEMBRE","GOXWILLER",(255,79,139)),
          ("toulouse","TOULOUSE","SAMEDI 21 NOVEMBRE","SALLE AMIRA",(92,227,110)),
          ("lyon","LYON","VENDREDI 27 NOVEMBRE","LE GOLD RÉCEPTION",(255,90,46))]
def unb(s, w=900):
    f = ImageFont.truetype(f"{S}/Unbounded.ttf", s); f.set_variation_by_axes([w]); return f
def anton(s): return ImageFont.truetype(f"{S}/Anton.ttf", s)
def tw(d, t, f, tr=0): return sum(d.textlength(c, font=f) for c in t) + tr * (len(t) - 1)
def center(d, y, t, f, fill, tr=0):
    x = (W - tw(d, t, f, tr)) / 2; b = d.textbbox((0, 0), t, font=f)
    for c in t: d.text((x, y - b[1]), c, font=f, fill=fill); x += d.textlength(c, font=f) + tr
    return b[3] - b[1]
def fit(mk, t, size, tr, maxw, d):
    f = mk(size)
    while tw(d, t, f, tr) > maxw: size -= 2; f = mk(size)
    return f
tiles = []
for slug, ville, date, salle, acc in villes:
    src = Image.open(f"{S}/fonds/{slug}.jpg").convert("RGB").resize((W, H))
    sharp = ImageEnhance.Brightness(src).enhance(0.85)
    soft = ImageEnhance.Color(ImageEnhance.Brightness(src.filter(ImageFilter.GaussianBlur(4))).enhance(0.5)).enhance(0.9)
    spot = Image.new("L", (W, H), 0); ImageDraw.Draw(spot).ellipse((W*0.12, H*0.16, W*0.88, H*0.84), fill=255)
    spot = spot.filter(ImageFilter.GaussianBlur(110))
    im = Image.composite(soft, sharp, spot).convert("RGBA")
    giant = Image.new("RGBA", (W, H), (0, 0, 0, 0)); dg = ImageDraw.Draw(giant)
    fg = fit(anton, "WAWA", 640, 10, W - 30, dg); b = dg.textbbox((0, 0), "WAWA", font=fg)
    x = (W - tw(dg, "WAWA", fg, 10)) / 2
    for c in "WAWA": dg.text((x, (H - (b[3] - b[1])) / 2 - b[1]), c, font=fg, fill=(255, 255, 255, 56)); x += dg.textlength(c, font=fg) + 10
    im = Image.alpha_composite(im, giant.filter(ImageFilter.GaussianBlur(1.2)))
    txt = Image.new("RGBA", (W, H), (0, 0, 0, 0)); d = ImageDraw.Draw(txt)
    f_wawa = unb(96, 900); f_ville = fit(lambda s: unb(s, 900), ville, 150, 4, 1080, d)
    f_date = unb(46, 600); f_salle = unb(36, 500)
    hs = [d.textbbox((0, 0), t, font=f)[3] - d.textbbox((0, 0), t, font=f)[1] for t, f in [("WAWA", f_wawa), (ville, f_ville), (date, f_date), (salle, f_salle)]]
    total = hs[0] + 26 + hs[1] + 36 + hs[2] + 18 + hs[3]
    y = (H - total) / 2
    y += center(d, y, "WAWA", f_wawa, (255, 255, 255, 255), 14) + 26
    ville_layer = Image.new("RGBA", (W, H), (0, 0, 0, 0)); dv = ImageDraw.Draw(ville_layer)
    center(dv, y, ville, f_ville, acc + (255,), 4); y += hs[1] + 36
    y += center(d, y, date, f_date, (255, 255, 255, 255), 6) + 18
    center(d, y, salle, f_salle, (255, 255, 255, 215), 8)
    def glow(layer, color, r, k):
        g = layer.filter(ImageFilter.GaussianBlur(r)); c = Image.new("RGBA", (W, H), color + (0,)); c.putalpha(g.split()[3].point(lambda a: min(255, int(a * k)))); return c
    shadow = ville_layer.filter(ImageFilter.GaussianBlur(6)); sh = Image.new("RGBA", (W, H), (0, 0, 0, 0)); sh.putalpha(shadow.split()[3].point(lambda a: int(a * 0.85)))
    im = Image.alpha_composite(im, glow(ville_layer, acc, 30, 0.75))
    im = Image.alpha_composite(im, sh)
    im = Image.alpha_composite(im, glow(txt, (255, 255, 255), 14, 0.45))
    im = Image.alpha_composite(Image.alpha_composite(im, ville_layer), txt)
    out = im.convert("RGB"); out.save(f"{K}/miniatures-v2/wawa-{slug}-miniature.jpg", quality=92); tiles.append(out)
sheet = Image.new("RGB", (2 * 650, 2 * 370), (14, 12, 20))
for i, t in enumerate(tiles): sheet.paste(t.resize((640, 360)), (5 + (i % 2) * 650, 5 + (i // 2) * 370))
sheet.save(f"{K}/miniatures-v2/proposition-neon-geant.jpg", quality=90)
