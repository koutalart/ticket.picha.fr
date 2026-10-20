"""Reel Halloween 1080x1920, 21 s : visuel, clips de Le D et Jah D One, DJ, puis l'offre. Sans son (musique ajoutée dans Instagram)."""
import subprocess

from commun import *
from stories_motion import Element, calque, el_badge, el_logo, el_texte, clamp, W, H

FPS, D = 30, 21.0
SRC = "/Users/tkg976/Dev/digit-ticket/apercu-assistant/innocent-event/video/source"
GRADE = "eq=brightness=-0.05:contrast=1.08:saturation=1.05,colorchannelmixer=rr=1.08:gg=0.72:bb=0.68,vignette=PI/4"


class Plage(Element):
    """Élément visible de debut à fin (fondu de sortie de 0,3 s)."""

    def __init__(self, el, fin):
        self.__dict__.update(el.__dict__)
        self.fin = fin

    def dessine(self, cadre, t, sortie):
        Element.dessine(self, cadre, t, sortie * (1 - clamp((t - self.fin) / 0.3)))


def plage(paire, fin):
    return Plage(paire[0] if isinstance(paire, tuple) else paire, fin)


def elements():
    els = [Plage(Element(calque(lambda c: degrade(c, 1000, force=210)), 0.0, "monte", duree=0.01), 99)]
    # 0 à 3,5 s : le visuel, accroche
    from serie_base import bande_basse
    els.append(Plage(Element(calque(lambda c: bande_basse(c, 1530, h_fondu=60)), 0.0, "monte", duree=0.01), 3.2))
    els.append(plage(el_texte(1600, "HALLOWEEN AU 5/5", unb(58, 800), ACC, 8, glow=ROUGE, debut=0.4), 3.2))
    els.append(plage(el_texte(1720, "SAMEDI 31 OCTOBRE", unb(46, 700), BLANC, 4, debut=0.8), 3.2))
    # 3,5 à 8,5 s : Le D
    els.append(plage(el_texte(1380, "LE D", unb(220, 900), BLANC, 6, glow=ROUGE, debut=3.8, mode="pop"), 8.2))
    els.append(plage(el_texte(1640, "SHOWCASE", unb(70, 800), ACC, 12, debut=4.3), 8.2))
    # 8,5 à 12,5 s : Jah D One
    els.append(plage(el_texte(1400, "JAH D ONE", ajuste("JAH D ONE", 170, 900, 6, 1000), BLANC, 6, glow=ROUGE, debut=8.8, mode="pop"), 12.2))
    els.append(plage(el_texte(1640, "SHOWCASE", unb(70, 800), ACC, 12, debut=9.3), 12.2))
    # 12,5 à 16 s : les DJ
    els.append(plage(el_texte(1300, "AUX PLATINES", unb(44, 700), ACC, 10, debut=12.7), 15.7))
    y = 1390
    for i, n in enumerate(["DJ SWEETY", "ASSKABOY", "DJ BVNG'Z"]):
        els.append(plage(el_texte(y, n, ajuste(n, 100, 900, 4, 960), BLANC, 4, glow=ROUGE, debut=13.0 + i * 0.35), 15.7))
        y += 140
    # 16 à 21 s : l'offre
    els.append(plage(el_logo(170, 130, 16.2), 20.8))
    els.append(plage(el_badge(1180, "VENTE FLASH", unb(36, 800), debut=16.4), 20.8))
    els.append(plage(el_texte(1290, "15 €", unb(260, 900), BLANC, 6, glow=ACC, debut=16.7, mode="pop"), 20.8))
    els.append(plage(el_texte(1580, "100 PLACES SEULEMENT", unb(52, 800), ACC, 4, debut=17.3), 20.8))
    els.append(plage(el_badge(1720, "RÉSERVE SUR INNOCENT976.YT", unb(34, 800), bg=ACC, fg=NOIR, debut=17.9, pulse=True), 20.8))
    return els


def fond_video(sortie):
    v = f"{S}/visuel-vertical.jpg"
    vert = lambda src, a, b, crop: f"[{src}]trim={a}:{b},setpts=PTS-STARTPTS,{crop}scale={W}:{H},fps={FPS},setsar=1,{GRADE},format=yuv420p,settb=1/{FPS},setpts=N/{FPS}/TB"
    filtre = ";".join([
        f"[0:v]scale=1350:2400,zoompan=z='1+0.0012*on':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={int(3.75 * FPS)}:s={W}x{H}:fps={FPS},setsar=1,format=yuv420p,trim=0:3.75,settb=1/{FPS},setpts=N/{FPS}/TB[a]",
        f"[1:v]crop=900:1600:119:0,scale=1350:2400,zoompan=z='1+0.0010*on':x='iw/2-(iw/zoom/2)':y='ih/3-(ih/zoom/3)':d={int(5.25 * FPS)}:s={W}x{H}:fps={FPS},setsar=1,format=yuv420p,trim=0:5.25,settb=1/{FPS},setpts=N/{FPS}/TB[b]",
        vert("2:v", 42, 46.25, "crop=608:1080:416:0,") + "[c]",
        vert("3:v", 24, 27.75, "crop=608:1080:656:0,boxblur=10:2,eq=brightness=0.04:saturation=1.2,") + "[d]",
        f"[4:v]scale=1350:2400,boxblur=24:2,eq=brightness=-0.38,zoompan=z='1.04+0.0008*on':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={int(5.25 * FPS)}:s={W}x{H}:fps={FPS},setsar=1,format=yuv420p,trim=0:5.25,settb=1/{FPS},setpts=N/{FPS}/TB[e]",
        f"[a][b]xfade=transition=fade:duration=0.25:offset=3.5,settb=1/{FPS},setpts=N/{FPS}/TB[ab]",
        f"[ab][c]xfade=transition=fade:duration=0.25:offset=8.5,settb=1/{FPS},setpts=N/{FPS}/TB[abc]",
        f"[abc][d]xfade=transition=fade:duration=0.25:offset=12.5,settb=1/{FPS},setpts=N/{FPS}/TB[abcd]",
        f"[abcd][e]xfade=transition=fade:duration=0.25:offset=16,format=yuv420p[o]",
    ])
    subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
                    "-loop", "1", "-framerate", str(FPS), "-t", "4", "-i", v,
                    "-loop", "1", "-framerate", str(FPS), "-t", "6", "-i", f"{S}/photo-le-d.jpg",
                    "-i", f"{SRC}/jah-d-one-wyfl.mp4", "-i", f"{SRC}/le-d-2k225.mp4",
                    "-loop", "1", "-framerate", str(FPS), "-t", "6", "-i", v,
                    "-filter_complex", filtre, "-map", "[o]", "-an", "-t", str(D),
                    "-c:v", "libx264", "-preset", "fast", "-crf", "14", sortie], check=True)


def rendre():
    fond = f"{S}/reel-fond.mp4"
    fond_video(fond)
    els = elements()
    sortie = f"{SORTIE}/motion/reel-halloween.mp4"
    proc = subprocess.Popen(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", fond,
                             "-f", "rawvideo", "-pix_fmt", "rgba", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
                             "-filter_complex", "[0:v][1:v]overlay=shortest=1,format=yuv420p[o]", "-map", "[o]", "-an",
                             "-c:v", "libx264", "-preset", "slow", "-crf", "22", "-profile:v", "high", "-movflags", "+faststart", sortie],
                            stdin=subprocess.PIPE)
    for n in range(int(FPS * D)):
        t = n / FPS
        cadre = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        for e in els:
            e.dessine(cadre, t, 1.0)
        proc.stdin.write(cadre.tobytes())
    proc.stdin.close()
    proc.wait()
    print("ok", sortie)


if __name__ == "__main__":
    rendre()
