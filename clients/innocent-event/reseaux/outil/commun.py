"""Outils de mise en page des visuels réseaux sociaux Halloween (Innocent Event)."""
from PIL import Image, ImageDraw, ImageFont, ImageFilter, ImageEnhance

S = "/private/tmp/claude-501/-Users-tkg976-Dev-digit-ticket/1e72ba5a-4f57-4eb0-9f97-9076f4070674/scratchpad"
POLICES = "/private/tmp/claude-501/-Users-tkg976-Dev-digit-ticket/c5aa8529-f7d4-424e-99b0-e945bca20281/scratchpad"
SORTIE = "/Users/tkg976/Dev/digit-ticket/apercu-assistant/innocent-event/reseaux"

ACC = (247, 148, 29)
ROUGE = (220, 30, 30)
BLANC = (255, 255, 255)
NOIR = (20, 8, 8)

VISUEL_16_9 = Image.open(f"{S}/visuel-client.webp").convert("RGB")
VISUEL = Image.open(f"{S}/visuel-vertical.jpg").convert("RGB")
FLYER = Image.open(f"{S}/flyer-halloween.jpg").convert("RGB")
_LOGO = Image.open(f"{S}/logo-innocent.png").convert("RGBA")

ACCROCHES = {
    "J-5": ("J-5", "SORS TON COSTUME"),
    "J-4": ("J-4", "LE D x JAH D ONE EN LIVE"),
    "J-3": ("J-3", "VIENS DÉGUISÉ(E)"),
    "J-2": ("J-2", "RAMÈNE TA TEAM"),
    "DEMAIN": ("DEMAIN SOIR", "DERNIÈRE LIGNE DROITE"),
    "CE-SOIR": ("CE SOIR", "DÈS 20:00 AU 5/5"),
}


def unb(s, w=900):
    f = ImageFont.truetype(f"{POLICES}/Unbounded.ttf", int(s))
    f.set_variation_by_axes([w])
    return f


def logo_blanc(h):
    l = _LOGO.resize((int(_LOGO.width * h / _LOGO.height), int(h)), Image.LANCZOS)
    b = Image.new("RGBA", l.size, (255, 255, 255, 0))
    b.putalpha(l.split()[3])
    return b


def fond(W, H, img=VISUEL, blur=26, dark=0.4, cx=0.5, zoom=1.0):
    k = max(W / img.width, H / img.height) * zoom
    im = img.resize((int(img.width * k) + 1, int(img.height * k) + 1), Image.LANCZOS)
    x = int((im.width - W) * cx)
    y = (im.height - H) // 2
    im = im.crop((x, y, x + W, y + H))
    if blur:
        im = im.filter(ImageFilter.GaussianBlur(blur))
    return ImageEnhance.Brightness(im).enhance(dark).convert("RGBA")


def largeur(d, t, f, tr=0):
    return sum(d.textlength(c, font=f) for c in t) + tr * (len(t) - 1)


def ajuste(t, s, w, tr, maxw):
    d = ImageDraw.Draw(Image.new("RGBA", (9, 9)))
    while largeur(d, t, unb(s, w), tr) > maxw:
        s -= 2
    return unb(s, w)


def calque_texte(size, y, t, f, col, tr=0, cx=None):
    lay = Image.new("RGBA", size, (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    b = d.textbbox((0, 0), t, font=f)
    x = (cx if cx is not None else size[0] / 2) - largeur(d, t, f, tr) / 2
    for c in t:
        if not c.isspace():
            d.text((x, y - b[1]), c, font=f, fill=col)
        x += d.textlength(c, font=f) + tr
    return lay, b[3] - b[1]


def effets(img, lay, glow=None, ombre=0.7):
    if glow:
        g = Image.new("RGBA", img.size, glow + (0,))
        g.putalpha(lay.split()[3].filter(ImageFilter.GaussianBlur(22)).point(lambda a: int(a * 0.8)))
        img.alpha_composite(g)
    sh = Image.new("RGBA", img.size, (0, 0, 0, 0))
    sh.putalpha(lay.split()[3].filter(ImageFilter.GaussianBlur(10)).point(lambda a: int(a * ombre)))
    img.alpha_composite(sh)
    img.alpha_composite(lay)


def texte(img, y, t, f, col, tr=0, glow=None):
    lay, h = calque_texte(img.size, y, t, f, col, tr)
    effets(img, lay, glow)
    return h


def badge(img, y, t, f, bg=ROUGE, fg=BLANC, pad=(34, 16), echelle=1.0, alpha=255):
    d0 = ImageDraw.Draw(Image.new("RGBA", (9, 9)))
    w = largeur(d0, t, f, 4) + 2 * pad[0]
    b = d0.textbbox((0, 0), t, font=f)
    h = b[3] - b[1] + 2 * pad[1]
    lay = Image.new("RGBA", (int(w) + 4, int(h) + 4), (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    d.rounded_rectangle((0, 0, w, h), radius=h // 2, fill=bg + (alpha,))
    xx = pad[0]
    for c in t:
        d.text((xx, pad[1] - b[1]), c, font=f, fill=fg + (alpha,))
        xx += d.textlength(c, font=f) + 4
    if echelle != 1.0:
        lay = lay.resize((max(1, int(lay.width * echelle)), max(1, int(lay.height * echelle))), Image.LANCZOS)
    img.alpha_composite(lay, (int((img.width - lay.width) / 2), int(y + (h - lay.height) / 2)))
    return h


def carte(img, card, y):
    x = (img.width - card.width) // 2
    sh = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(sh).rounded_rectangle((x - 6, y - 6, x + card.width + 6, y + card.height + 6), radius=28, fill=(0, 0, 0, 210))
    img.alpha_composite(sh.filter(ImageFilter.GaussianBlur(26)))
    m = Image.new("L", card.size, 0)
    ImageDraw.Draw(m).rounded_rectangle((0, 0, card.width, card.height), radius=24, fill=255)
    c = card.convert("RGBA")
    c.putalpha(m)
    img.alpha_composite(c, (x, y))


def degrade(img, h, top=False, force=235):
    W, H = img.size
    m = Image.new("L", (1, h))
    for i in range(h):
        m.putpixel((0, i), int(force * (i / h if not top else 1 - i / h)))
    o = Image.new("RGBA", (W, h), (8, 2, 2, 0))
    o.putalpha(m.resize((W, h)))
    img.alpha_composite(o, (0, 0 if top else H - h))


def prix_barre(img, y, t, f):
    d = ImageDraw.Draw(img)
    w = largeur(d, t, f)
    bb = d.textbbox((0, 0), t, font=f)
    x = (img.width - w) / 2
    d.text((x, y - bb[1]), t, font=f, fill=(210, 210, 210))
    ym = y + (bb[3] - bb[1]) / 2
    d.line((x - 10, ym + 10, x + w + 10, ym - 10), fill=ROUGE, width=10)
    return bb[3] - bb[1]


def planche(noms, fichier, H=520):
    th = [Image.open(f"{SORTIE}/{n}.jpg") for n in noms]
    tw = [int(i.width * H / i.height) for i in th]
    pl = Image.new("RGB", (sum(tw) + 10 * (len(th) + 1), H + 20), (20, 16, 20))
    x = 10
    for i, w in zip(th, tw):
        pl.paste(i.resize((w, H)), (x, 10))
        x += w + 10
    pl.save(f"{SORTIE}/{fichier}", quality=85)
