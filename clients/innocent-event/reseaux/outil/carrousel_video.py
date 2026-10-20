"""Carrousel vidéo 1080x1350 (10 s par diapositive, en boucle) : accroche, Le D, Jah D One. Bouton innocent976.yt sur chacune."""
import math
import subprocess
import sys

from commun import *

W, H, FPS, D = 1080, 1350, 30, 10.0
SRC = "/Users/tkg976/Dev/digit-ticket/apercu-assistant/innocent-event/video/source"
GRADE = "eq=brightness=-0.10:contrast=1.08:saturation=1.05,colorchannelmixer=rr=1.08:gg=0.72:bb=0.68,vignette=PI/4"
SORTIE_C = f"{SORTIE}/carrousel"


def clamp(x): return max(0.0, min(1.0, x))
def out_cubic(x): x = clamp(x); return 1 - (1 - x) ** 3
def out_back(x):
    x = clamp(x); c = 1.70158
    return 1 + (c + 1) * (x - 1) ** 3 + c * (x - 1) ** 2


class El:
    def __init__(self, img, x, y, debut, mode="monte", duree=0.7, pulse=False):
        self.img, self.x, self.y, self.debut, self.mode, self.duree, self.pulse = img, x, y, debut, mode, duree, pulse

    def dessine(self, cadre, t, sortie):
        p = (t - self.debut) / self.duree
        if p <= 0:
            return
        img, x, y = self.img, self.x, self.y
        a = clamp(p * 1.4) * sortie
        if self.mode == "monte":
            y += int((1 - out_cubic(p)) * 60)
        elif self.mode == "droite":
            x += int((1 - out_cubic(p)) * 420)
        elif self.mode == "pop":
            k = 0.6 + 0.4 * out_back(p)
            if self.pulse and p >= 1:
                k *= 1 + 0.035 * math.sin((t - self.debut) * 6)
            if abs(k - 1) > 0.002:
                img = img.resize((max(1, int(img.width * k)), max(1, int(img.height * k))), Image.LANCZOS)
                x += (self.img.width - img.width) // 2
                y += (self.img.height - img.height) // 2
        if a < 0.999:
            img = img.copy()
            img.putalpha(img.split()[3].point(lambda v: int(v * a)))
        cadre.alpha_composite(img, (x, y))


def recadre(calque):
    b = calque.getbbox() or (0, 0, 1, 1)
    return calque.crop(b), b[0], b[1]


def el_texte(y, t, f, col, tr=0, glow=None, debut=0.0, mode="monte", cx=None):
    lay, h = calque_texte((W, H), y, t, f, col, tr, cx=cx)
    c = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    effets(c, lay, glow)
    img, x, yy = recadre(c)
    return El(img, x, yy, debut, mode), h


def el_cta(debut):
    c = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    badge(c, 1215, "RÉSERVE SUR INNOCENT976.YT", unb(32, 800), bg=ACC, fg=NOIR, pad=(38, 18))
    img, x, y = recadre(c)
    return El(img, x, y, debut, "pop", pulse=True)


def el_degrade(h, top=False, force=220):
    c = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    degrade(c, h, top=top, force=force)
    return El(c, 0, 0, 0.0, "fixe", duree=0.01)


def diapo_accroche():
    els = [el_degrade(380)]
    els.append(el_cta(0.8))
    fond = (f"[0:v]crop=720:900:0:0,scale=1350:1688,zoompan=z='1.02+0.02*sin(2*PI*on/{int(FPS * D)})':"
            f"x='iw/2-(iw/zoom/2)':y='ih/3-(ih/zoom/3)':d=1:s={W}x{H}:fps={FPS},setsar=1,format=yuv420p[bg]")
    return els, ["-loop", "1", "-framerate", str(FPS), "-t", str(D), "-i", f"{S}/visuel-vertical.jpg"], fond


def diapo_artiste(detoure, clip, debut_clip, nom, sous, crop_clip):
    els = [el_degrade(520), el_degrade(240, top=True, force=170)]
    p = Image.open(detoure).convert("RGBA")
    p = p.crop(p.getbbox())
    h = 1060
    p = p.resize((int(p.width * h / p.height), h), Image.LANCZOS)
    ombre = Image.new("RGBA", p.size, (0, 0, 0, 0))
    ombre.putalpha(p.split()[3].filter(ImageFilter.GaussianBlur(24)).point(lambda v: int(v * 0.6)))
    lueur = Image.new("RGBA", p.size, ROUGE + (0,))
    lueur.putalpha(p.split()[3].filter(ImageFilter.GaussianBlur(40)).point(lambda v: int(v * 0.55)))
    calque = Image.new("RGBA", (p.width + 80, p.height), (0, 0, 0, 0))
    calque.alpha_composite(lueur, (40, 0)); calque.alpha_composite(ombre, (60, 10)); calque.alpha_composite(p, (40, 0))
    els.append(El(calque, W - calque.width + 60, H - calque.height, 0.2, "droite", duree=0.9))
    els.append(El(logo_blanc(110), int((W - logo_blanc(110).width) / 2), 40, 0.1, "monte"))
    cx = 300
    e, hh = el_texte(760, nom, ajuste(nom, 170, 900, 4, 520), BLANC, 4, glow=ROUGE, debut=0.9, mode="pop", cx=cx); els.append(e)
    e, _ = el_texte(760 + hh + 30, sous, unb(54, 800), ACC, 8, debut=1.3, cx=cx); els.append(e)
    e, _ = el_texte(760 + hh + 120, "SAMEDI 31 OCTOBRE", unb(30, 700), BLANC, 3, debut=1.6, cx=cx); els.append(e)
    e, _ = el_texte(760 + hh + 170, "AU 5/5", unb(30, 700), BLANC, 3, debut=1.7, cx=cx); els.append(e)
    els.append(el_cta(2.1))
    fond = (f"[0:v]trim={debut_clip}:{debut_clip + D},setpts=PTS-STARTPTS,{crop_clip}scale={W}:{H},fps={FPS},setsar=1,boxblur=5:1,{GRADE},eq=brightness=-0.12,format=yuv420p[bg]")
    return els, ["-i", clip], fond


DIAPOS = {
    "01-accroche": diapo_accroche,
    "02-le-d": lambda: diapo_artiste(f"{S}/le-d-detoure.png", f"{SRC}/le-d-2k225.mp4", 24, "LE D", "SHOWCASE", "crop=864:1080:528:0,"),
    "03-jah-d-one": lambda: diapo_artiste(f"{S}/jah-detoure.png", f"{SRC}/jah-d-one-wyfl.mp4", 40, "JAH D ONE", "SHOWCASE", "crop=864:1080:288:0,"),
}


def rendre(nom):
    els, entree, fond = DIAPOS[nom]()
    sortie = f"{SORTIE_C}/{nom}.mp4"
    proc = subprocess.Popen(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", *entree,
                             "-f", "rawvideo", "-pix_fmt", "rgba", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
                             "-filter_complex", f"{fond};[bg][1:v]overlay=shortest=1,format=yuv420p[o]", "-map", "[o]", "-an", "-t", str(D),
                             "-c:v", "libx264", "-preset", "slow", "-crf", "22", "-profile:v", "high", "-movflags", "+faststart", sortie],
                            stdin=subprocess.PIPE)
    for n in range(int(FPS * D)):
        t = n / FPS
        fin = clamp((D - 0.15 - t) / 0.6)
        cadre = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        for e in els:
            e.dessine(cadre, t, 1.0 if e.mode == "fixe" else fin)
        proc.stdin.write(cadre.tobytes())
    proc.stdin.close()
    proc.wait()
    print("ok", sortie)


if __name__ == "__main__":
    rendre(sys.argv[1])
