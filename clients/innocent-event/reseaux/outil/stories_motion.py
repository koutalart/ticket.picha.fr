"""Stories animées 1080x1920, 8 s en boucle : fond flyer en zoom lent (ffmpeg), éléments animés par-dessus (Pillow)."""
import math
import subprocess
import sys

from commun import *

W, H, FPS, D = 1080, 1920, 30, 8.0


def clamp(x): return max(0.0, min(1.0, x))
def out_cubic(x): x = clamp(x); return 1 - (1 - x) ** 3
def out_back(x):
    x = clamp(x); c = 1.70158
    return 1 + (c + 1) * (x - 1) ** 3 + c * (x - 1) ** 2


class Element:
    """Un élément pré-rendu (texte + halo + ombre), animé par translation, échelle et opacité."""

    def __init__(self, calque, debut, mode="monte", pulse=False, duree=0.7):
        bbox = calque.getbbox() or (0, 0, 1, 1)
        self.x, self.y = bbox[0], bbox[1]
        self.img = calque.crop(bbox)
        self.alpha = self.img.split()[3]
        self.debut, self.mode, self.pulse, self.duree = debut, mode, pulse, duree

    def dessine(self, cadre, t, sortie):
        p = (t - self.debut) / self.duree
        if p <= 0:
            return
        a = clamp(p * 1.4) * sortie
        img, x, y = self.img, self.x, self.y
        if self.mode == "monte":
            y += int((1 - out_cubic(p)) * 60)
        elif self.mode == "pop":
            k = 0.6 + 0.4 * out_back(p)
            if self.pulse and p >= 1:
                k *= 1 + 0.035 * math.sin((t - self.debut) * 6)
            if abs(k - 1) > 0.002:
                img = img.resize((max(1, int(img.width * k)), max(1, int(img.height * k))), Image.LANCZOS)
                x += (self.img.width - img.width) // 2
                y += (self.img.height - img.height) // 2
        elif self.mode == "glisse":
            x += int((1 - out_cubic(p)) * 120)
        if a < 0.999:
            img = img.copy()
            img.putalpha((img.split()[3]).point(lambda v: int(v * a)))
        cadre.alpha_composite(img, (x, y))


def calque(fn):
    """Construit un calque plein format en appelant fn(img) sur un fond transparent."""
    c = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    fn(c)
    return c


def el_texte(y, t, f, col, tr=0, glow=None, debut=0.0, mode="monte", pulse=False):
    lay, h = calque_texte((W, H), y, t, f, col, tr)
    c = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    effets(c, lay, glow)
    return Element(c, debut, mode, pulse), h


def el_badge(y, t, f, bg=ROUGE, fg=BLANC, debut=0.0, pulse=False):
    c = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    h = badge(c, y, t, f, bg=bg, fg=fg)
    return Element(c, debut, "pop", pulse), h


def el_logo(y, h, debut=0.0):
    c = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    lg = logo_blanc(h)
    c.alpha_composite(lg, (int((W - lg.width) / 2), int(y)))
    return Element(c, debut, "monte")


def story_compte(cle):
    titre, sous = ACCROCHES[cle]
    els = [el_logo(180, 130, 0.1)]
    y = 560
    e, h = el_texte(y, "HALLOWEEN AU 5/5", unb(40, 600), ACC, 10, debut=0.3); els.append(e); y += h + 40
    e, h = el_texte(y, titre, ajuste(titre, 420, 900, 8, 980), ACC, 8, glow=ROUGE, debut=0.6, mode="pop"); els.append(e); y += h + 60
    e, h = el_texte(y, sous, ajuste(sous, 60, 800, 4, 980), BLANC, 4, debut=1.3); els.append(e); y += h + 40
    e, h = el_texte(y, "LE D x JAH D ONE", unb(44, 700), BLANC, 4, debut=1.6); els.append(e); y += h + 120
    e, _ = el_badge(y, "RÉSERVE SUR INNOCENT976.YT", unb(30, 800), bg=ACC, fg=NOIR, debut=2.1, pulse=True); els.append(e)
    return els


def story_annonce():
    from serie_base import bande_basse
    els = [Element(calque(lambda c: bande_basse(c, 1530, h_fondu=60)), 0.3, "monte", duree=0.8)]
    y = 1545
    e, h = el_badge(y, "VENTE FLASH", unb(28, 800), debut=0.8); els.append(e); y += h + 20
    e, h = el_texte(y, "15 €", unb(118, 900), BLANC, 4, glow=ACC, debut=1.1, mode="pop"); els.append(e); y += h + 20
    e, h = el_texte(y, "100 PLACES SEULEMENT", unb(34, 700), ACC, 4, debut=1.5); els.append(e); y += h + 26
    e, _ = el_texte(y, "LIEN EN BIO", unb(28, 600), BLANC, 10, debut=1.8, mode="pop", pulse=True); els.append(e)
    return els


def story_team():
    els = [el_logo(180, 130, 0.1)]
    y = 620
    e, h = el_texte(y, "AVEC QUI", unb(130, 900), BLANC, 4, debut=0.4); els.append(e); y += h + 20
    e, h = el_texte(y, "TU VIENS ?", unb(130, 900), ACC, 4, glow=ROUGE, debut=0.8, mode="pop"); els.append(e); y += h + 60
    e, h = el_texte(y, "IDENTIFIE TA TEAM DE MONSTRES", ajuste("IDENTIFIE TA TEAM DE MONSTRES", 46, 700, 3, 980), BLANC, 3, debut=1.4); els.append(e); y += h + 50
    yb = y
    def cadre_tag(c):
        ImageDraw.Draw(c).rounded_rectangle((140, yb, 940, yb + 300), radius=40, fill=(0, 0, 0, 110), outline=(255, 255, 255, 190), width=4)
        lay, _ = calque_texte((W, H), yb + 125, "TAGUE TES AMIS ICI", unb(30, 600), (255, 255, 255, 200), 8)
        c.alpha_composite(lay)
    els.append(Element(calque(cadre_tag), 1.9, "pop", pulse=True))
    e, _ = el_texte(1700, "SAMEDI 31 OCTOBRE, LE 5/5", unb(34, 600), BLANC, 4, debut=2.3); els.append(e)
    return els


def story_epuisee():
    els = [el_logo(180, 110, 0.1)]
    y = 560
    e, h = el_badge(y, "VENTE FLASH ÉPUISÉE", unb(34, 800), debut=0.4); els.append(e); y += h + 70
    e, h = el_texte(y, "MERCI !", unb(170, 900), BLANC, 4, glow=ACC, debut=0.8, mode="pop"); els.append(e); y += h + 70
    e, h = el_texte(y, "IL RESTE LES ENTRÉES", unb(48, 800), BLANC, 3, debut=1.4); els.append(e); y += h + 30
    e, h = el_texte(y, "20 €", unb(220, 900), ACC, 6, glow=ROUGE, debut=1.8, mode="pop"); els.append(e); y += h + 40
    e, h = el_texte(y, "EN LIGNE, 25 € SUR PLACE", unb(36, 600), BLANC, 4, debut=2.3); els.append(e); y += h + 140
    e, _ = el_badge(y, "RÉSERVE SUR INNOCENT976.YT", unb(30, 800), bg=ACC, fg=NOIR, debut=2.7, pulse=True); els.append(e)
    return els


def rendre(els, sortie, cx, net=False):
    # fond : visuel du client recadré à la verticale, zoom qui respire (aller-retour), assombri ; boucle sans coupure
    traitement = "eq=saturation=1.05" if net else "boxblur=30:2,eq=brightness=-0.40:saturation=1.1"
    zp = (f"[0:v]scale=1350:2400,{traitement},"
          f"zoompan=z='{"1.03+0.02" if net else "1.05+0.04"}*sin(2*PI*on/{int(FPS * D)})':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=1:s={W}x{H}:fps={FPS},"
          f"vignette=PI/4,format=yuv420p[bg];[bg][1:v]overlay=shortest=1,format=yuv420p[o]")
    proc = subprocess.Popen(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
                             "-loop", "1", "-framerate", str(FPS), "-t", str(D), "-i", f"{S}/visuel-vertical.jpg",
                             "-f", "rawvideo", "-pix_fmt", "rgba", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
                             "-filter_complex", zp, "-map", "[o]", "-an", "-t", str(D),
                             "-c:v", "libx264", "-preset", "slow", "-crf", "23", "-profile:v", "high", "-pix_fmt", "yuv420p",
                             "-movflags", "+faststart", sortie], stdin=subprocess.PIPE)
    for n in range(int(FPS * D)):
        t = n / FPS
        fin = clamp((D - 0.15 - t) / 0.6)
        cadre = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        for e in els:
            e.dessine(cadre, t, fin)
        proc.stdin.write(cadre.tobytes())
    proc.stdin.close()
    proc.wait()


STORIES = {
    "02-story-annonce": (story_annonce, 0.5),
    "06-story-j-5": (lambda: story_compte("J-5"), 0.15),
    "07-story-team": (story_team, 0.85),
    "08-story-j-4": (lambda: story_compte("J-4"), 0.85),
    "09-story-j-3": (lambda: story_compte("J-3"), 0.15),
    "10-story-j-2": (lambda: story_compte("J-2"), 0.85),
    "11-story-demain": (lambda: story_compte("DEMAIN"), 0.15),
    "12-story-ce-soir": (lambda: story_compte("CE-SOIR"), 0.85),
    "15-story-flash-epuisee": (story_epuisee, 0.15),
}

if __name__ == "__main__":
    nom = sys.argv[1]
    fn, cx = STORIES[nom]
    rendre(fn(), f"{SORTIE}/motion/{nom}.mp4", cx, net=(nom == "02-story-annonce"))
    print("ok", nom)
