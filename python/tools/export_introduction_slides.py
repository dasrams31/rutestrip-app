"""Export the defense deck for rutestrip.ai.id/introduction (Windows with PowerPoint):
    python python/tools/export_introduction_slides.py [deck.pptx]

slide images (WebP) into public/images/introduction/ and titles + text into resources/data/introduction.json.
Uses the deck open in PowerPoint when there is one, otherwise opens it read-only."""
import json, os, re, sys, tempfile
import win32com.client
from PIL import Image
from pptx import Presentation

REPO = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
DECK = os.path.abspath(sys.argv[1]) if len(sys.argv) > 1 else os.path.join(REPO, "bahan sidang", "Sidang_RuteStrip_Rama.pptx")
IMG_DIR = os.path.join(REPO, "public", "images", "introduction")
DATA = os.path.join(REPO, "resources", "data", "introduction.json")
TMP = os.path.join(tempfile.gettempdir(), "rutestrip_introduction_png")
HEAD_FONT = "Bahnschrift SemiBold Condensed"

os.makedirs(IMG_DIR, exist_ok=True); os.makedirs(TMP, exist_ok=True); os.makedirs(os.path.dirname(DATA), exist_ok=True)
own = False
try:
    app = win32com.client.GetActiveObject("PowerPoint.Application")
    pres = next(app.Presentations(i) for i in range(1, app.Presentations.Count + 1) if app.Presentations(i).FullName.lower() == DECK.lower())
    assert pres.Saved, "save the deck in PowerPoint first"
except (StopIteration, Exception) as e:
    if isinstance(e, AssertionError):
        raise
    app = win32com.client.DispatchEx("PowerPoint.Application")
    pres = app.Presentations.Open(DECK, ReadOnly=True, Untitled=False, WithWindow=False)
    own = True
n = pres.Slides.Count
for f in os.listdir(IMG_DIR):
    if re.fullmatch(r"slide-\d+\.webp", f):
        os.remove(os.path.join(IMG_DIR, f))
total = 0
for i in range(1, n + 1):
    png = os.path.join(TMP, f"{i:02d}.png")
    pres.Slides(i).Export(png, "PNG", 1920, 1080)
    out = os.path.join(IMG_DIR, f"slide-{i:02d}.webp")
    Image.open(png).convert("RGB").save(out, "WEBP", quality=82, method=6)
    total += os.path.getsize(out)
if own:
    pres.Close(); app.Quit()

prs = Presentation(DECK)
slides = []
for i, s in enumerate(prs.slides, 1):
    title, parts = None, []
    for sh in s.shapes:
        if sh.has_text_frame and sh.text_frame.text.strip():
            t = sh.text_frame.text.strip()
            if re.fullmatch(r"\d+ / \d+", t) or ("23.12.2956" in t and "Rama" in t and i > 1 and len(t) < 80):
                continue  # footer
            runs = [r for p in sh.text_frame.paragraphs for r in p.runs]
            if title is None and runs and runs[0].font.name == HEAD_FONT and not re.fullmatch(r"[\d.,%\u2212 ]+", t):
                title = " ".join(t.split())
                continue
            parts.append(" ".join(p.text.strip() for p in sh.text_frame.paragraphs if p.text.strip()))
        elif getattr(sh, "has_table", False) and sh.has_table:
            for row in sh.table.rows:
                cells = [c.text.strip() for c in row.cells if c.text.strip()]
                if cells:
                    parts.append(": ".join([cells[0], ", ".join(cells[1:])]) if len(cells) > 1 else cells[0])
        elif sh.shape_type == 13 and sh.name.startswith("QR"):
            parts.append("Kode QR untuk memasang aplikasi: https://rutestrip.ai.id/install")
    slides.append({"n": i, "title": title or f"Slide {i}", "text": [p for p in parts if p]})
json.dump({"slides": slides}, open(DATA, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
print(n, "slides,", round(total / 1e6, 2), "MB of images")
for s in slides:
    print(s["n"], "|", s["title"])
