"""Visuels de base (annonce, vente flash, artistes, team) à partir du visuel vertical corrigé."""
from commun import *


def bande_basse(img, y0, h_fondu=160):
    W, H = img.size
    degrade_zone = Image.new("RGBA", (W, h_fondu), (8, 2, 2, 0))
    m = Image.new("L", (1, h_fondu))
    for i in range(h_fondu):
        m.putpixel((0, i), int(250 * i / h_fondu))
    degrade_zone.putalpha(m.resize((W, h_fondu)))
    img.alpha_composite(degrade_zone, (0, y0 - h_fondu))
    ImageDraw.Draw(img).rectangle((0, y0, W, H), fill=(10, 3, 3, 250))


def post_annonce():
    a = fond(1080, 1350, dark=0.32)
    carte(a, VISUEL.crop((0, 0, 720, 1000)).resize((600, 833), Image.LANCZOS), 40)
    y = 905
    y += badge(a, y, "VENTE FLASH", unb(30, 800)) + 30
    y += texte(a, y, "100 PLACES À 15 €", unb(84, 900), BLANC, 3, glow=ACC) + 30
    y += texte(a, y, "ENSUITE 20 €", unb(36, 600), ACC, 6) + 40
    texte(a, y, "RÉSERVE SUR INNOCENT976.YT", ajuste("RÉSERVE SUR INNOCENT976.YT", 38, 700, 3, 980), BLANC, 3)
    a.convert("RGB").save(f"{SORTIE}/01-post-annonce.jpg", quality=92)


def story_annonce():
    b = VISUEL.resize((1080, 1920), Image.LANCZOS).convert("RGBA")
    bande_basse(b, 1530, h_fondu=60)
    y = 1545
    y += badge(b, y, "VENTE FLASH", unb(28, 800)) + 20
    y += texte(b, y, "15 €", unb(118, 900), BLANC, 4, glow=ACC) + 20
    y += texte(b, y, "100 PLACES SEULEMENT", unb(34, 700), ACC, 4) + 26
    texte(b, y, "LIEN EN BIO", unb(28, 600), BLANC, 10)
    b.convert("RGB").save(f"{SORTIE}/02-story-annonce.jpg", quality=92)


def post_vente_flash():
    c = fond(1080, 1350, blur=40, dark=0.32)
    y = 250
    y += badge(c, y, "VENTE FLASH", unb(34, 800)) + 60
    y += texte(c, y, "15 €", unb(300, 900), BLANC, 6, glow=ACC) + 30
    y += prix_barre(c, y, "20 €", unb(80, 700)) + 70
    y += texte(c, y, "100 PLACES SEULEMENT", unb(44, 800), ACC, 4) + 26
    y += texte(c, y, "ENSUITE 20 €", unb(32, 600), BLANC, 6) + 90
    texte(c, y, "HALLOWEEN, SAMEDI 31 OCTOBRE AU 5/5", ajuste("HALLOWEEN, SAMEDI 31 OCTOBRE AU 5/5", 30, 600, 4, 960), BLANC, 4)
    c.alpha_composite(logo_blanc(90), (int((1080 - logo_blanc(90).width) / 2), 110))
    c.convert("RGB").save(f"{SORTIE}/03-post-vente-flash.jpg", quality=92)


def post_artiste(nom, crop, titre, sous):
    ph = FLYER.crop(crop)
    ph = ph.resize((1080, int(ph.height * 1080 / ph.width)), Image.LANCZOS)
    e = fond(1080, 1350, dark=0.5)
    e.alpha_composite(ph.crop((0, 0, 1080, min(1350, ph.height))).convert("RGBA"))
    degrade(e, 620)
    degrade(e, 200, top=True)
    y = 900
    y += texte(e, y, titre, ajuste(titre, 190, 900, 6, 1000), BLANC, 6, glow=ROUGE) + 20
    y += texte(e, y, sous, unb(60, 800), ACC, 10) + 40
    texte(e, y, "SAMEDI 31 OCTOBRE AU 5/5", unb(34, 600), BLANC, 4)
    e.alpha_composite(logo_blanc(130), (int((1080 - logo_blanc(130).width) / 2), 45))
    e.convert("RGB").save(f"{SORTIE}/{nom}.jpg", quality=92)


def post_le_d():
    ph = Image.open(f"{S}/photo-le-d.jpg").convert("RGB")
    h = int(ph.width * 1350 / 1080)
    y0 = min(int(ph.height * 0.02), ph.height - h)
    ph = ph.crop((0, y0, ph.width, y0 + h)).resize((1080, 1350), Image.LANCZOS)
    e = ph.convert("RGBA")
    degrade(e, 640)
    degrade(e, 220, top=True, force=180)
    y = 920
    y += texte(e, y, "LE D", unb(190, 900), BLANC, 6, glow=ROUGE) + 20
    y += texte(e, y, "SHOWCASE", unb(60, 800), ACC, 10) + 40
    texte(e, y, "SAMEDI 31 OCTOBRE AU 5/5", unb(34, 600), BLANC, 4)
    e.alpha_composite(logo_blanc(130), (int((1080 - logo_blanc(130).width) / 2), 45))
    e.convert("RGB").save(f"{SORTIE}/04-post-le-d.jpg", quality=92)


def post_jah():
    ph = Image.open(f"{S}/photo-jah.jpg").convert("RGB")
    h = int(ph.width * 1350 / 1080)
    y0 = ph.height - h
    ph = ph.crop((0, y0, ph.width, y0 + h)).resize((1080, 1350), Image.LANCZOS)
    e = ph.convert("RGBA")
    degrade(e, 640)
    degrade(e, 220, top=True, force=150)
    y = 920
    y += texte(e, y, "JAH D ONE", ajuste("JAH D ONE", 190, 900, 6, 1000), BLANC, 6, glow=ROUGE) + 20
    y += texte(e, y, "SHOWCASE", unb(60, 800), ACC, 10) + 40
    texte(e, y, "SAMEDI 31 OCTOBRE AU 5/5", unb(34, 600), BLANC, 4)
    e.alpha_composite(logo_blanc(130), (int((1080 - logo_blanc(130).width) / 2), 45))
    e.convert("RGB").save(f"{SORTIE}/05-post-jah-d-one.jpg", quality=92)


def story_team():
    h = fond(1080, 1920, blur=40, dark=0.42)
    h.alpha_composite(logo_blanc(130), (int((1080 - logo_blanc(130).width) / 2), 180))
    y = 620
    y += texte(h, y, "AVEC QUI", unb(130, 900), BLANC, 4) + 20
    y += texte(h, y, "TU VIENS ?", unb(130, 900), ACC, 4, glow=ROUGE) + 60
    y += texte(h, y, "IDENTIFIE TA TEAM DE MONSTRES", ajuste("IDENTIFIE TA TEAM DE MONSTRES", 46, 700, 3, 980), BLANC, 3) + 50
    ImageDraw.Draw(h).rounded_rectangle((140, y, 940, y + 300), radius=40, fill=(0, 0, 0, 110), outline=(255, 255, 255, 190), width=4)
    texte(h, y + 125, "TAGUE TES AMIS ICI", unb(30, 600), (255, 255, 255, 200), 8)
    texte(h, 1700, "SAMEDI 31 OCTOBRE, LE 5/5", unb(34, 600), BLANC, 4)
    h.convert("RGB").save(f"{SORTIE}/07-story-team.jpg", quality=92)


post_annonce()
story_annonce()
post_vente_flash()
post_le_d()
post_jah()
story_team()
print("ok")
