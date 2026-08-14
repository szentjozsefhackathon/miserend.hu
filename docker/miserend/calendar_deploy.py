import hashlib
import os
import shutil
import re
import sys
import urllib.request

# A script fájl helye
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

# Globális változók
# Relatív útvonalak a scripthez képest
FROM_PATH = os.path.join(SCRIPT_DIR, '..', '..', 'calendar', 'dist', 'mcal', 'browser')
TO_PATH = os.path.join(SCRIPT_DIR, '..', '..', 'webapp')

if len(sys.argv) > 1:
    FROM_PATH = sys.argv[1]

if len(sys.argv) > 2:
    TO_PATH = sys.argv[2]

GOOGLE_FONT_URL = re.compile(r'url\((https://fonts\.gstatic\.com/[^)"\']+)\)')


def vendor_google_fonts(css, destination_dir):
    """A Google-betűtípusokat letöltjük, és magunk szolgáljuk ki.

    Az Angular Material témája a Robotót és a Material Symbolst a
    `fonts.gstatic.com`-ról hozza. Ennek két baja volt:

    * ADATVÉDELEM — minden látogató böngészője hívta a Google-t, hozzájárulás nélkül,
      az IP-jével és a User-Agentjével együtt. A /gdpr szövege ilyet nem említ.
    * SEBESSÉG — a böngésző a betűtípusokra vár az oldalbetöltés befejezéséig. Mérve:
      ugyanaz a templomoldal a gstatic elérhetetlensége mellett 45 másodperc fölött is
      elidőzött, magunk kiszolgálva 1 másodperc. Ugyanez fojtotta meg a CI funkcionális
      tesztjeit is, ahol a Panther a teljes betöltésre vár.

    Build időben fut, tehát a látogató felé nem marad külső hívás. Ha a letöltés nem
    sikerül, az adott URL VÁLTOZATLAN marad: a hiányzó betűtípus ne buktasson buildet.
    """
    fonts_dir = os.path.join(destination_dir, '..', 'fonts')
    os.makedirs(fonts_dir, exist_ok=True)

    letoltve = {}

    def replace(match):
        url = match.group(1)
        if url in letoltve:
            return f'url({letoltve[url]})'

        # Tartalomtól független, stabil név: ugyanaz az URL mindig ugyanazt a fájlt adja,
        # így a build megismételhető, és a felesleges fájlcserét is elkerüljük.
        kiterjesztes = os.path.splitext(url.split('?')[0])[1] or '.woff2'
        nev = hashlib.sha1(url.encode('utf-8')).hexdigest()[:16] + kiterjesztes
        cel = os.path.join(fonts_dir, nev)

        if not os.path.exists(cel):
            try:
                keres = urllib.request.Request(url, headers={'User-Agent': 'miserend.hu build'})
                with urllib.request.urlopen(keres, timeout=30) as valasz:
                    tartalom = valasz.read()
                with open(cel, 'wb') as f:
                    f.write(tartalom)
                print(f"[INFO] Font downloaded: {nev} ({len(tartalom)} bytes)")
            except Exception as e:
                print(f"[WARN] Font download failed, keeping remote URL: {url} ({e})")
                return match.group(0)

        letoltve[url] = f'/fonts/{nev}'
        return f'url(/fonts/{nev})'

    uj = GOOGLE_FONT_URL.sub(replace, css)

    maradek = len(GOOGLE_FONT_URL.findall(uj))
    print(f"[INFO] Google fonts vendored: {len(letoltve)}, still remote: {maradek}")

    return uj


def vendor_google_fonts_in_js(js_path, destination_dir):
    """Ugyanez a JS-csomagra.

    A kiemelt `styles-*.css` átírása önmagában NEM elég: az Angular a globális stílust a
    JS-be is beágyazza, és futásidőben `<style>` elemként injektálja. Emiatt a böngésző a
    JS-ből érkező `@font-face` szabályok miatt továbbra is a Google-t hívta — kimérve az
    oldal így ugyanúgy 45 másodperc fölött időzött, hiába volt a CSS már rendben.

    Csak URL-literálokat cserélünk a beágyazott stílusokban, rövidebbre; a minifikált
    kódot ez nem érinti.
    """
    if not js_path or not os.path.exists(js_path):
        return

    with open(js_path, 'r', encoding='utf-8') as f:
        tartalom = f.read()

    if 'fonts.gstatic.com' not in tartalom:
        return

    uj = vendor_google_fonts(tartalom, destination_dir)

    with open(js_path, 'w', encoding='utf-8') as f:
        f.write(uj)

    print(f"[INFO] Google fonts vendored in JS bundle: {os.path.basename(js_path)}")


# A meglévő rendszer nem kezeli jól a reszponzív betűméretet, így a default méret szerint átkonvertálunk
def process_css(source_file, destination_dir):
    # A fájl megnyitása és olvasása
    with open(source_file, 'r') as file:
        content = file.read()
    
    # A regex minta, amely a számokat és rem egységeket keres
    pattern = r'(\d*\.?\d+)rem'

    def convert(match):
        # Eredeti szám (pl. .75rem vagy 1.5rem)
        rem_value = float(match.group(1)) if match.group(1) else 0
        
        # Konvertálás px-be: 1rem = 16px
        px_value = rem_value * 16
        
        # Kerekítés egész számra
        rounded_px_value = round(px_value)
        
        # Visszatérünk az új px értékkel
        return f'{rounded_px_value}px'
    
    # Az összes rem egységet konvertáljuk
    new_content = re.sub(pattern, convert, content)

    # A Google-betűtípusokat magunk szolgáljuk ki (l. vendor_google_fonts).
    new_content = vendor_google_fonts(new_content, destination_dir)

    # A bemeneti fájlnév feldolgozása a kimenethez
    file_name, file_extension = os.path.splitext(os.path.basename(source_file))
   
    output_file = os.path.join(destination_dir, f"{file_name}{file_extension}")

    
    try:
        print(f"[INFO] Save new file: {output_file}")
        with open(output_file, 'w') as file:
            file.write(new_content)
        print("[INFO] File saved successfully.")
    except PermissionError:
        print(f"[ERROR] You do not have permission to save the file: {output_file}")
    except Exception as e:
        print(f"[ERROR] Unknown error while saving file: {e}")

def find_file_by_pattern(path, pattern):
    for fname in os.listdir(path):
        if re.fullmatch(pattern, fname):
            return fname
    return None

def delete_file_by_pattern(path, pattern):
    for fname in os.listdir(path):
        if re.fullmatch(pattern, fname):
            os.remove(os.path.join(path, fname))
            print(f"Deleted: {fname}")

def update_layout_template(css_file):
    layout_path = os.path.join(TO_PATH, "templates", "layout.twig")
    with open(layout_path, "r", encoding="utf-8") as f:
        lines = f.readlines()

    updated_lines = []
    inside_head = False
    css_line_done = False

    i = 0
    while i < len(lines):
        line = lines[i]
        stripped = line.strip()
        updated_lines.append(line)

        if "<head" in stripped:
            inside_head = True

        if inside_head and "{# ### calendar-css ### #}" in stripped:
            # Marker megtalálva, nézzük a következő sort
            if i + 1 < len(lines) and 'href="/css/styles' in lines[i + 1]:
                updated_lines.append(f'\t\t<link rel="stylesheet" href="/css/{css_file}?v={{{{ mcal_version }}}}">\n')
                i += 1  # átugorjuk az eredeti sort
            else:
                updated_lines.append(f'\t\t<link rel="stylesheet" href="/css/{css_file}?v={{{{ mcal_version }}}}">\n')
            css_line_done = True

        if inside_head and not css_line_done and "{% block extraHead %}{% endblock %}" in stripped:
            # Marker nincs, de extraHead blokk van → szúrjuk be
            updated_lines.insert(len(updated_lines) - 1, '\t\t{# ### calendar-css ### #}\n')
            updated_lines.insert(len(updated_lines) - 1, f'\t\t<link rel="stylesheet" href="/css/{css_file}?v={{{{ mcal_version }}}}">\n')
            updated_lines.insert(len(updated_lines) - 1, '\n')
            css_line_done = True

        if "</head>" in stripped:
            inside_head = False

        i += 1

    with open(layout_path, "w", encoding="utf-8") as f:
        f.writelines(updated_lines)


def replace_calendar_app(template_path, main_js, polyfills_js):
    with open(template_path, "r", encoding="utf-8") as f:
        lines = f.readlines()

    # Insert Twig variables at the top; escape braces in the f-string by doubling them.
    first_line = f'{{% set polyfills = "{polyfills_js}" %}}{{% set main = "{main_js}" %}}\n'

    if lines:
        lines[0] = first_line
    else:
        lines = [first_line]

    with open(template_path, "w", encoding="utf-8") as f:
        f.writelines(lines)


def replace_or_insert_calendar_app(template_path, main_js, polyfills_js):
    with open(template_path, "r", encoding="utf-8") as f:
        lines = f.readlines()

    filename = os.path.basename(template_path)
    new_lines = []
    inserted = False
    after_content_block = False

    # app_html body (no marker) and a separate marker line so we don't duplicate/accidentally add extra '{'
    app_html_body = (
        '<mcal class="calendar-app">'
        '<app-root></app-root>'
        f'<script src="/js/mcal/{polyfills_js}" type="module"></script>'
        f'<script src="/js/mcal/{main_js}" type="module"></script></mcal>\n'
    )
    marker_line = '{#calendar-app#}'

    i = 0
    while i < len(lines):
        line = lines[i]
        stripped = line.strip()

        if stripped.startswith("{#calendar-app#}") and not inserted:
            indent = line[:len(line) - len(stripped) - 1]
            new_lines.append(indent + marker_line + app_html_body)
            inserted = True
        else:
            new_lines.append(line)

        i += 1

    with open(template_path, "w", encoding="utf-8") as f:
        f.writelines(new_lines)

def update_home_twig(template_path, main_js, polyfills_js):
    with open(template_path, "r", encoding="utf-8") as f:
        lines = f.readlines()

    new_lines = []
    inserted = False

    app_html = (
        '{#calendar-app#}<mcal class="calendar-app">'
        '<app-root></app-root>'
        f'<script src="/js/mcal/{polyfills_js}" type="module"></script>'
        f'<script src="/js/mcal/{main_js}" type="module"></script></mcal>\n'
    )

    i = 0
    while i < len(lines):
        line = lines[i]
        stripped = line.strip()
        new_lines.append(line)

        if '<div class="miseurlap">' in stripped:
            if (i + 1) < len(lines) and lines[i + 1].strip().startswith("{#calendar-app#}"):
                new_lines.append(app_html)
                i += 1  # kihagyjuk a régi sort
            else:
                new_lines.append(app_html)
            inserted = True

        i += 1

    with open(template_path, "w", encoding="utf-8") as f:
        f.writelines(new_lines)

def main():
    # === CSS ===
    css_to_path = os.path.join(TO_PATH, "css")
    delete_file_by_pattern(css_to_path, r"styles(-[a-zA-Z0-9]{8})?\.css")

    css_file = find_file_by_pattern(FROM_PATH, r"styles(-[a-zA-Z0-9]{8})?\.css")
    if css_file:
        process_css(os.path.join(FROM_PATH, css_file), css_to_path)
        update_layout_template(css_file)
    else:
        print("The file styles-xxxxxxxx.css cannot be found!")

    # === JS+HTML ===
    js_to_path = os.path.join(TO_PATH, "js", "mcal")
    if os.path.exists(js_to_path):
        shutil.rmtree(js_to_path)
    os.makedirs(js_to_path, exist_ok=True)

    main_js = find_file_by_pattern(FROM_PATH, r"main(-[a-zA-Z0-9]{8})?\.js")
    polyfills_js = find_file_by_pattern(FROM_PATH, r"polyfills(-[a-zA-Z0-9]{8})?\.js")

    if main_js:
        shutil.copy(os.path.join(FROM_PATH, main_js), os.path.join(js_to_path, main_js))
        # A globális stílust az Angular a JS-be is beágyazza, tehát a betűtípusokat
        # ITT is ki kell váltani — a kiemelt CSS átírása önmagában nem elég.
        vendor_google_fonts_in_js(os.path.join(js_to_path, main_js), os.path.join(TO_PATH, "css"))
    if polyfills_js:
        shutil.copy(os.path.join(FROM_PATH, polyfills_js), os.path.join(js_to_path, polyfills_js))

    if not (main_js and polyfills_js):
        print("The required JS file(s) could not be found!")
        return

    # angularjs.twig => Egyetlen script angular alkalmazáshoz, amit be lehet szúrni a twig fájlokba
    church_twig_path = os.path.join(TO_PATH, "templates", "angularjs.twig")    
    replace_calendar_app(church_twig_path, main_js, polyfills_js)
    
    # === i18n fájl másolása ===
    i18n_src = os.path.join(FROM_PATH, "i18n", "hu.json")
    i18n_dst_dir = os.path.join(TO_PATH, "i18n")
    i18n_dst = os.path.join(i18n_dst_dir, "hu.json")
    
    if os.path.exists(i18n_src):
        os.makedirs(i18n_dst_dir, exist_ok=True)
        shutil.copy(i18n_src, i18n_dst)
    else:
        print("Source i18n/hu.json file not found!")


    # === cal_images mappa másolása ===
    cal_images_src = os.path.join(FROM_PATH, "cal_images")
    cal_images_dst = os.path.join(TO_PATH, "cal_images")

    if os.path.exists(cal_images_dst):
        shutil.rmtree(cal_images_dst)

    if os.path.exists(cal_images_src):
        shutil.copytree(cal_images_src, cal_images_dst)
        print(f"cal_images folder copied: {cal_images_dst}")
    else:
        print("Source cal_images folder not found!")


if __name__ == "__main__":
    print("[PYTHON] Deploy script starting...")
    main()
    print("[PYTHON] Deploy script finished [OK]")
