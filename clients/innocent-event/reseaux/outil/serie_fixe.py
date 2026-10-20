"""Suite de la série fixe : stories du compte à rebours, DJ, vente flash épuisée, demain soir, ce soir, merci."""
from commun import *


def story_compte(cle, nom):
    titre, sous = ACCROCHES[cle]
    g = fond(1080, 1920, blur=8, dark=0.62, cx={"J-5": 0.0, "J-4": 1.0, "J-3": 0.0, "J-2": 1.0, "DEMAIN": 0.0, "CE-SOIR": 1.0}[cle])
    g.alpha_composite(logo_blanc(130), (int((1080 - logo_blanc(130).width) / 2), 180))
    y = 560
    y += texte(g, y, "HALLOWEEN AU 5/5", unb(40, 600), ACC, 10) + 40
    y += texte(g, y, titre, ajuste(titre, 420, 900, 8, 980), ACC, 8, glow=ROUGE) + 60
    y += texte(g, y, sous, ajuste(sous, 60, 800, 4, 980), BLANC, 4) + 40
    y += texte(g, y, "LE D x JAH D ONE", unb(44, 700), BLANC, 4) + 120
    badge(g, y, "RÉSERVE SUR INNOCENT976.YT", unb(30, 800), bg=ACC, fg=NOIR)
    g.convert("RGB").save(f"{SORTIE}/{nom}.jpg", quality=92)


def post_djs(nom):
    p = fond(1080, 1350, blur=10, dark=0.45)
    p.alpha_composite(logo_blanc(90), (int((1080 - logo_blanc(90).width) / 2), 90))
    y = 270
    y += texte(p, y, "AUX PLATINES", unb(40, 600), ACC, 12) + 60
    for n in ["DJ SWEETY", "ASSKABOY", "DJ BVNG'Z"]:
        y += texte(p, y, n, ajuste(n, 130, 900, 4, 960), BLANC, 4, glow=ROUGE) + 50
    y += 30
    y += texte(p, y, "AFROBEATS, AMAPIANO, AFRO-CARIBÉEN", ajuste("AFROBEATS, AMAPIANO, AFRO-CARIBÉEN", 32, 600, 3, 980), ACC, 3) + 20
    y += texte(p, y, "HIP-HOP, R&B, KOMPA", unb(32, 600), ACC, 3) + 80
    texte(p, y, "SAMEDI 31 OCTOBRE AU 5/5", unb(34, 600), BLANC, 4)
    p.convert("RGB").save(f"{SORTIE}/{nom}.jpg", quality=92)


def flash_epuisee(nom, W, H):
    p = fond(W, H, blur=18, dark=0.35)
    p.alpha_composite(logo_blanc(110), (int((W - logo_blanc(110).width) / 2), 120 if H < 1500 else 180))
    y = 330 if H < 1500 else 560
    y += badge(p, y, "VENTE FLASH ÉPUISÉE", unb(34, 800)) + 70
    y += texte(p, y, "MERCI !", unb(170, 900), BLANC, 4, glow=ACC) + 70
    y += texte(p, y, "IL RESTE LES ENTRÉES", unb(48, 800), BLANC, 3) + 30
    y += texte(p, y, "20 €", unb(220, 900), ACC, 6, glow=ROUGE) + 40
    y += texte(p, y, "EN LIGNE, 25 € SUR PLACE", unb(36, 600), BLANC, 4) + (70 if H < 1500 else 140)
    badge(p, y, "RÉSERVE SUR INNOCENT976.YT", unb(30, 800), bg=ACC, fg=NOIR)
    p.convert("RGB").save(f"{SORTIE}/{nom}.jpg", quality=92)


def post_jour(nom, titre, sous):
    p = Image.new("RGBA", (1080, 1350), (10, 3, 3, 255))
    carte(p, VISUEL.resize((1020, 574), Image.LANCZOS), 60)
    y = 720
    y += texte(p, y, titre, ajuste(titre, 160, 900, 6, 1000), ACC, 6, glow=ROUGE) + 40
    y += texte(p, y, sous, ajuste(sous, 54, 800, 4, 980), BLANC, 4) + 60
    y += texte(p, y, "SAMEDI 31 OCTOBRE AU 5/5, MAMOUDZOU", ajuste("SAMEDI 31 OCTOBRE AU 5/5, MAMOUDZOU", 32, 600, 3, 980), BLANC, 3) + 70
    badge(p, y, "RÉSERVE SUR INNOCENT976.YT", unb(30, 800), bg=ACC, fg=NOIR)
    p.convert("RGB").save(f"{SORTIE}/{nom}.jpg", quality=92)


def post_merci(nom):
    p = fond(1080, 1350, blur=22, dark=0.42)
    p.alpha_composite(logo_blanc(120), (int((1080 - logo_blanc(120).width) / 2), 160))
    y = 470
    y += texte(p, y, "MERCI", unb(230, 900), BLANC, 6, glow=ROUGE) + 40
    y += texte(p, y, "POUR CETTE NUIT", unb(70, 800), ACC, 4) + 70
    y += texte(p, y, "LES PHOTOS ARRIVENT", unb(40, 700), BLANC, 4) + 26
    y += texte(p, y, "IDENTIFIE-TOI ET PARTAGE", unb(40, 700), BLANC, 4) + 90
    texte(p, y, "À TRÈS VITE AVEC INNOCENT EVENT", ajuste("À TRÈS VITE AVEC INNOCENT EVENT", 34, 600, 4, 980), ACC, 4)
    p.convert("RGB").save(f"{SORTIE}/{nom}.jpg", quality=92)


story_compte("J-5", "06-story-j-5")
for cle, nom in [("J-4", "08-story-j-4"), ("J-3", "09-story-j-3"), ("J-2", "10-story-j-2"), ("DEMAIN", "11-story-demain"), ("CE-SOIR", "12-story-ce-soir")]:
    story_compte(cle, nom)
post_djs("13-post-djs")
flash_epuisee("14-post-flash-epuisee", 1080, 1350)
flash_epuisee("15-story-flash-epuisee", 1080, 1920)
post_jour("16-post-demain-soir", "DEMAIN SOIR", "DERNIÈRE LIGNE DROITE")
post_jour("17-post-ce-soir", "CE SOIR", "DÈS 20:00 AU 5/5")
post_merci("18-post-merci")
planche(["06-story-j-5", "08-story-j-4", "09-story-j-3", "10-story-j-2", "11-story-demain", "12-story-ce-soir", "13-post-djs", "14-post-flash-epuisee", "15-story-flash-epuisee", "16-post-demain-soir", "17-post-ce-soir", "18-post-merci"], "planche-reseaux-suite.jpg", H=440)
print("ok")
