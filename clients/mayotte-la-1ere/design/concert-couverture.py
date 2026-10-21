import sys, glob
from PIL import Image, ImageDraw, ImageFont
S = sys.argv[1]; T = S + "/ftv/"; I = S + "/img/"
W, H = 1600, 900; LINE = 450
INK, GREY, LIGHT, YEL = (35, 35, 35), (205, 208, 212), (233, 235, 238), (255, 195, 0)
def font(s, w):
    f = ImageFont.truetype(T + "Outfit.ttf", s); f.set_variation_by_axes([w]); return f
img = Image.new("RGB", (W, H), LIGHT); d = ImageDraw.Draw(img)
# motif : frise de grands cercles, comme la bannière de la chaîne
R = 92
for row in range(-1, 6):
    for col in range(-1, 12):
        cx = col * 2 * R + (R if row % 2 else 0); cy = LINE + (row - 2) * 2 * R
        d.ellipse((cx - R, cy - R, cx + R, cy + R), fill=(225, 228, 231))
# photos d'artistes, bandes pleine hauteur à droite
photos = sorted(glob.glob(I + "p10-*.jpg")); x0, bw = 990, 122
for k, p in enumerate(photos):
    ph = Image.open(p).convert("RGB"); r = max(bw / ph.width, H / ph.height)
    ph = ph.resize((int(ph.width * r) + 1, int(ph.height * r) + 1), Image.LANCZOS); cx = (ph.width - bw) // 2
    img.paste(ph.crop((cx, 0, cx + bw, H)), (x0 + k * bw, 0))
# logo de la chaîne : son point jaune posé sur la ligne de frise
logo = Image.open(T + "logo-mayotte-Dwjba.png").convert("RGBA"); logo = logo.crop(logo.getbbox())
scale = 330 / logo.width; logo = logo.resize((330, int(logo.height * scale)), Image.LANCZOS)
px = logo.load(); ys = [y for y in range(logo.height) for x in range(logo.width) if px[x, y][3] > 200 and px[x, y][0] > 200 and px[x, y][2] < 80]
xs = [x for y in range(logo.height) for x in range(logo.width) if px[x, y][3] > 200 and px[x, y][0] > 200 and px[x, y][2] < 80]
dot_y = sum(ys) / len(ys); dot_r = (max(xs) - min(xs)) / 2
img.paste(logo, (80, int(LINE - dot_y)), logo)
# frise de demi-points sur la ligne, jusqu'aux photos, et demi-point jaune au bord droit
for x in range(470, 960, int(dot_r * 2.4)):
    d.ellipse((x - dot_r / 2, LINE - dot_r / 2, x + dot_r / 2, LINE + dot_r / 2), fill=GREY)
d.ellipse((W - dot_r, LINE - dot_r, W + dot_r, LINE + dot_r), fill=YEL)
# titre aligné sur la ligne
tf = font(62, 700)
d.text((470, LINE - 18), "Le Concert", font=tf, fill=INK, anchor="ls")
d.text((470, LINE + 18), "des Générations", font=tf, fill=INK, anchor="lt")
y = LINE + 112
for t, f in [("Samedi 14 novembre 2026, 20h15", font(26, 700)), ("Dans les locaux de Mayotte La 1ère", font(24, 400)),
             ("Entrée gratuite sur inscription", font(24, 400))]:
    d.text((470, y), t, font=f, fill=INK); y += 38
# signature de la campagne et production, en pied
c40 = Image.open(I + "p01-2-FormXob.a607754cdd96f8efd0a2886a50802346.png").convert("RGBA"); c40 = c40.crop(c40.getbbox()); c40.thumbnail((300, 46), Image.LANCZOS)
img.paste(c40, (80, H - 92), c40)
d.text((470, H - 70), "Une production KULTUR'M", font=font(18, 500), fill=INK, anchor="lm")
img.save(S + "/concert-couverture-ftv.jpg", quality=90)
img.resize((1200, 675), Image.LANCZOS).save(S + "/concert-apercu-ftv.jpg", quality=85); print("ok", dot_y, dot_r)
