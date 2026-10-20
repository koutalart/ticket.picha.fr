"""Stories 1080x1920 dans le style du carrousel vidéo, avec la musique de chaque artiste (10 s)."""
import subprocess
import sys

import carrousel_video as cv
from commun import *

W, H, FPS, D = 1080, 1920, 30, 10.0
cv.W, cv.H = W, H
SORTIE_S = f"{SORTIE}/stories-musique"


def artiste_detoure(chemin, h):
    p = Image.open(chemin).convert("RGBA")
    p = p.crop(p.getbbox())
    p = p.resize((int(p.width * h / p.height), h), Image.LANCZOS)
    fondu = Image.new("L", (1, h))
    for i in range(h):
        r = i / h
        fondu.putpixel((0, i), 255 if r < 0.55 else int(255 * max(0.0, 1 - (r - 0.55) / 0.40) ** 1.6))
    p.putalpha(Image.composite(p.split()[3], Image.new("L", p.size, 0), fondu.resize(p.size)))
    lueur = Image.new("RGBA", p.size, ROUGE + (0,))
    lueur.putalpha(p.split()[3].filter(ImageFilter.GaussianBlur(44)).point(lambda v: int(v * 0.55)))
    c = Image.new("RGBA", (p.width + 80, p.height + 40), (0, 0, 0, 0))
    c.alpha_composite(lueur, (40, 20))
    c.alpha_composite(p, (40, 20))
    return c


def logo_centre(debut=0.1):
    lg = logo_blanc(180)
    return cv.El(lg, int((W - lg.width) / 2), 150, debut, "monte")


def story_accroche():
    from serie_base import bande_basse
    els = [cv.El(cv.recadre(_calque(lambda c: bande_basse(c, 1460, h_fondu=120)))[0], 0, 1340, 0.0, "fixe", duree=0.01)]
    e, _ = cv.el_texte(1500, "TU VIENS ?", unb(150, 900), BLANC, 4, glow=ROUGE, debut=0.8, mode="pop"); els.append(e)
    e, _ = cv.el_texte(1700, "SAMEDI 31 OCTOBRE AU 5/5", unb(38, 700), ACC, 4, debut=1.3); els.append(e)
    fond = (f"[0:v]scale=1350:2400,zoompan=z='1.02+0.02*sin(2*PI*on/{int(FPS * D)})':x='iw/2-(iw/zoom/2)':y='ih/3-(ih/zoom/3)':"
            f"d=1:s={W}x{H}:fps={FPS},setsar=1,format=yuv420p[bg]")
    return els, ["-loop", "1", "-framerate", str(FPS), "-t", str(D), "-i", f"{S}/visuel-vertical.jpg"], fond


def _calque(fn):
    c = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    fn(c)
    return c


def story_artiste(detoure, clip, debut_clip, nom, crop_clip):
    els = [cv.el_degrade(380, top=True, force=200)]
    c = artiste_detoure(detoure, 1240)
    els.append(cv.El(c, int((W - c.width) / 2), 380, 0.2, "monte", duree=0.9))
    els.append(cv.el_degrade(760, force=240))
    els.append(logo_centre())
    e, hh = cv.el_texte(1360, nom, ajuste(nom, 170, 900, 4, 980), BLANC, 4, glow=ROUGE, debut=0.9, mode="pop"); els.append(e)
    e, _ = cv.el_texte(1360 + hh + 40, "SHOWCASE", unb(60, 800), ACC, 12, debut=1.3); els.append(e)
    e, _ = cv.el_texte(1360 + hh + 150, "SAMEDI 31 OCTOBRE AU 5/5", unb(36, 700), BLANC, 4, debut=1.6); els.append(e)
    fond = (f"[0:v]trim={debut_clip}:{debut_clip + D},setpts=PTS-STARTPTS,{crop_clip}scale={W}:{H},fps={FPS},setsar=1,"
            f"boxblur=6:1,{cv.GRADE},eq=brightness=-0.12,format=yuv420p[bg]")
    return els, ["-i", clip], fond


def story_reserve():
    els = []
    c = _calque(lambda c: carte(c, VISUEL.crop((0, 0, 720, 840)).resize((520, 607), Image.LANCZOS), 290))
    img, x, y = cv.recadre(c); els.append(cv.El(img, x, y, 0.2, "monte", duree=0.9))
    e, hh = cv.el_texte(1010, "RÉSERVE", unb(140, 900), BLANC, 4, debut=0.8); els.append(e)
    e, _ = cv.el_texte(1010 + hh + 24, "TA PLACE", unb(140, 900), ACC, 4, glow=ROUGE, debut=1.1, mode="pop"); els.append(e)
    c = _calque(lambda c: badge(c, 1360, "INNOCENT976.YT", unb(52, 800), bg=ACC, fg=NOIR, pad=(50, 24)))
    img, x, y = cv.recadre(c); els.append(cv.El(img, x, y, 1.6, "pop", pulse=True))
    e, _ = cv.el_texte(1520, "APPUIE SUR LE LIEN", unb(34, 700), BLANC, 8, debut=2.0); els.append(e)
    fond = (f"[0:v]scale=1350:2400,boxblur=30:2,eq=brightness=-0.42:saturation=1.1,"
            f"zoompan=z='1.05+0.03*sin(2*PI*on/{int(FPS * D)})':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=1:s={W}x{H}:fps={FPS},setsar=1,format=yuv420p[bg]")
    return els, ["-loop", "1", "-framerate", str(FPS), "-t", str(D), "-i", f"{S}/visuel-vertical.jpg"], fond


STORIES = {
    "01-story-accroche": (story_accroche, "01-accroche"),
    "02-story-le-d": (lambda: story_artiste(f"{S}/le-d-detoure.png", f"{cv.SRC}/le-d-2k225.mp4", 24, "LE D", "crop=608:1080:656:0,"), "02-le-d"),
    "03-story-jah-d-one": (lambda: story_artiste(f"{S}/jah-detoure.png", f"{cv.SRC}/jah-d-one-wyfl.mp4", 40, "JAH D ONE", "crop=608:1080:416:0,"), "03-jah-d-one"),
    "04-story-reserve": (story_reserve, "04-reserve"),
}


def rendre(nom):
    fn, son_cle = STORIES[nom]
    els, entree, fond = fn()
    son, debut_son = cv.SONS[son_cle]
    sortie = f"{SORTIE_S}/{nom}.mp4"
    proc = subprocess.Popen(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", *entree,
                             "-f", "rawvideo", "-pix_fmt", "rgba", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
                             "-ss", str(debut_son), "-t", str(D), "-i", son,
                             "-filter_complex", f"{fond};[bg][1:v]overlay=shortest=1,format=yuv420p[o];[2:a]afade=t=in:d=0.3,afade=t=out:st={D - 0.8}:d=0.8,loudnorm=I=-14:TP=-1.5[a]",
                             "-map", "[o]", "-map", "[a]", "-c:a", "aac", "-b:a", "192k", "-t", str(D),
                             "-c:v", "libx264", "-preset", "slow", "-crf", "22", "-profile:v", "high", "-movflags", "+faststart", sortie],
                            stdin=subprocess.PIPE)
    for n in range(int(FPS * D)):
        t = n / FPS
        fin = cv.clamp((D - 0.15 - t) / 0.6)
        cadre = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        for e in els:
            e.dessine(cadre, t, 1.0 if e.mode == "fixe" else fin)
        proc.stdin.write(cadre.tobytes())
    proc.stdin.close()
    proc.wait()
    print("ok", sortie)


if __name__ == "__main__":
    import os
    os.makedirs(SORTIE_S, exist_ok=True)
    rendre(sys.argv[1])
