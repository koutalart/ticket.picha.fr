"""Carrousel d'annonce Halloween : 7 images 1080x1350."""
import shutil

from commun import *

C = f"{SORTIE}/carrousel"


def fleche(img, texte_fleche="BALAYE"):
    d = ImageDraw.Draw(img)
    f = unb(26, 700)
    t = f"{texte_fleche}  →"
    w = largeur(d, t, f, 3)
    x, y = img.width - w - 50, img.height - 80
    d.rounded_rectangle((x - 22, y - 14, x + w + 22, y + 44), radius=29, fill=(0, 0, 0, 150))
    lay, _ = calque_texte(img.size, y, t, f, BLANC, 3, cx=x + w / 2)
    img.alpha_composite(lay)


def points(img, n, total=7):
    d = ImageDraw.Draw(img)
    x0 = img.width / 2 - (total - 1) * 11
    for i in range(total):
        r = 6 if i == n else 4
        d.ellipse((x0 + i * 22 - r, 1310 - r, x0 + i * 22 + r, 1310 + r), fill=ACC if i == n else (255, 255, 255, 140))


def couverture():
    v = VISUEL.crop((0, 0, 720, 900)).resize((1080, 1350), Image.LANCZOS).convert("RGBA")
    degrade(v, 260, force=200)
    fleche(v, "TOUT SAVOIR")
    return v


def reprend(nom):
    return Image.open(f"{SORTIE}/{nom}.jpg").convert("RGBA")


def infos():
    p = fond(1080, 1350, blur=40, dark=0.36)
    p.alpha_composite(logo_blanc(110), (int((1080 - logo_blanc(110).width) / 2), 90))
    y = 300
    y += texte(p, y, "LES INFOS", unb(90, 900), ACC, 4, glow=ROUGE) + 80
    for titre, ligne in [("QUAND", "SAMEDI 31 OCTOBRE, DÈS 20:00"), ("OÙ", "LE 5/5, BAR RESTAURANT LIVE"),
                         ("", "ROND-POINT DE LA BARGE, MAMOUDZOU"), ("THÈME", "HALLOWEEN, VIENS DÉGUISÉ(E)"),
                         ("TON BILLET", "E-BILLET IMMÉDIAT, QR CODE À L'ENTRÉE")]:
        if titre:
            y += texte(p, y, titre, unb(30, 700), ACC, 10) + 16
        y += texte(p, y, ligne, ajuste(ligne, 40, 800, 2, 980), BLANC, 2) + (50 if titre != "OÙ" else 18)
    return p


def appel():
    p = fond(1080, 1350, blur=34, dark=0.34)
    carte(p, VISUEL.crop((0, 0, 720, 840)).resize((420, 490), Image.LANCZOS), 110)
    y = 680
    y += texte(p, y, "RÉSERVE", unb(120, 900), BLANC, 4) + 20
    y += texte(p, y, "TA PLACE", unb(120, 900), ACC, 4, glow=ROUGE) + 60
    y += badge(p, y, "INNOCENT976.YT", unb(48, 800), bg=ACC, fg=NOIR, pad=(46, 22)) + 40
    texte(p, y, "LIEN EN BIO", unb(32, 600), BLANC, 10)
    return p


diapos = [couverture(), reprend("04-post-le-d"), reprend("05-post-jah-d-one"), reprend("13-post-djs"),
          reprend("03-post-vente-flash"), infos(), appel()]
import os
os.makedirs(C, exist_ok=True)
for i, d in enumerate(diapos):
    if 0 < i < 6:
        fleche(d)
    points(d, i)
    d.convert("RGB").save(f"{C}/{i + 1:02d}.jpg", quality=92)
th = [Image.open(f"{C}/{i + 1:02d}.jpg").resize((300, 375)) for i in range(7)]
pl = Image.new("RGB", (7 * 310 + 10, 395), (20, 16, 20))
for i, t in enumerate(th):
    pl.paste(t, (10 + i * 310, 10))
pl.save(f"{C}/planche-carrousel.jpg", quality=85)
print("ok")
