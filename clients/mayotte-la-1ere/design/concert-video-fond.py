import subprocess, sys
D = sys.argv[1]; OUT = sys.argv[2]
X = 0.4
SEG = [  # fichier, début (s), durée (s), recadrage ; le dernier plan finit là où commence le premier
    ("archives-40ans", 252, 3.0, "crop=860:484:210:96"),
    ("bodo", 99, 2.0, None),
    ("meli", 270, 2.2, None),
    ("annice-live", 71, 2.2, "crop=1060:596:60:0"),
    ("chaldi", 259, 2.0, "crop=1280:648:0:36"),
    ("maliza", 133, 2.0, "crop=960:540:160:0"),
    ("jahdone", 65, 2.0, "crop=960:540:160:0"),
    ("bodo", 133.5, 2.2, None),
    ("meli", 25, 2.0, None),
    ("bodo", 207, 2.4, None),
    ("archives-40ans", 249.0, 2.6, "crop=860:484:210:96"),
]
args, flt = ["ffmpeg", "-hide_banner", "-loglevel", "error", "-y"], []
for k, (f, ss, du, crop) in enumerate(SEG):
    args += ["-ss", str(ss), "-t", str(du + X), "-i", f"{D}/source/{f}.mp4"]
    flt.append(f"[{k}:v]{crop + ',' if crop else ''}scale=1280:720:force_original_aspect_ratio=increase,crop=1280:720,fps=24,setsar=1,format=yuv420p,settb=1/24,setpts=N/24/TB[v{k}]")
prev, off = "v0", SEG[0][2]
for k in range(1, len(SEG)):
    flt.append(f"[{prev}][v{k}]xfade=transition=fade:duration={X}:offset={off:.3f},settb=1/24,setpts=N/24/TB[x{k}]")
    prev = f"x{k}"; off += SEG[k][2]
args += ["-filter_complex", ";".join(flt), "-map", f"[{prev}]", "-t", "25", "-an", "-c:v", "libx264", "-preset", "fast", "-crf", "14", OUT]
print(sum(s[2] for s in SEG)); subprocess.run(args, check=True)
