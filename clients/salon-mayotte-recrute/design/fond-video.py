"""Fond vidéo 25 s en boucle à partir de la rétrospective 2024 (temps forts, sans les textes incrustés)."""
import subprocess, sys
SRC, OUT = sys.argv[1], sys.argv[2]
DEBUTS = [31.0, 16.5, 19.5, 25.0, 28.0, 35.62, 37.36, 41.68, 46.0, 51.7, 55.0, 67.0]
X = 0.5
D = (25 + len(DEBUTS) * X) / len(DEBUTS)
prep = "crop=1536:864:192:0,scale=1280:720:flags=lanczos,fps=30,setsar=1,format=yuv420p,settb=1/30,setpts=N/30/TB"
f = []
for i, s in enumerate(DEBUTS):
    a, b = (s + X, s + D) if i == 0 else (s, s + D)
    f.append(f"[0:v]trim={a}:{b},setpts=PTS-STARTPTS,{prep}[c{i}]")
f.append(f"[0:v]trim={DEBUTS[0]}:{DEBUTS[0] + X},setpts=PTS-STARTPTS,{prep}[tete]")
cur, t = "c0", D - X
for i in range(1, len(DEBUTS)):
    f.append(f"[{cur}][c{i}]xfade=transition=fade:duration={X}:offset={t - X:.4f},settb=1/30,setpts=N/30/TB[x{i}]")
    cur, t = f"x{i}", t + D - X
f.append(f"[{cur}][tete]xfade=transition=fade:duration={X}:offset={t - X:.4f},format=yuv420p[o]")
subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", SRC, "-filter_complex", ";".join(f),
                "-map", "[o]", "-an", "-t", "25", "-c:v", "libx264", "-preset", "fast", "-crf", "14", OUT], check=True)
print("ok", OUT, round(t, 3))
