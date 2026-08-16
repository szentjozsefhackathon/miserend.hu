import {Injectable} from '@angular/core';

/**
 * #816: a Leaflet betöltése FUTÁSIDŐBEN, nem a csomagba fordítva.
 *
 * A miserend.hu úgyis kiszolgálja a Leafletet a saját `node_modules`-ából (#661/#653),
 * és a templomoldal be is tölti a helyszín-panelhez — ott tehát a `window.L` már kész
 * van, mire a szerkesztő megnyílik. Ha nincs (pl. a szerkesztő önálló oldalán), akkor
 * innen töltjük be, ugyanarról a hosztról és ugyanabban a verzióban.
 *
 * Miért nem npm-függőség a naptárban? Mert akkor KÉT Leaflet-példány lenne az oldalon,
 * két verzióval, és ~150 KB-tal nagyobb csomag egy olyan funkcióért, amit a szerkesztők
 * töredéke használ. A #661 pont azt a rendetlenséget takarította el, hogy három sablon
 * három Leaflet-verziót húzott be.
 */
@Injectable({providedIn: 'root'})
export class LeafletLoaderService {

  private static readonly SCRIPT_URL = '/node_modules/leaflet/dist/leaflet.js';
  private static readonly STYLE_URL = '/node_modules/leaflet/dist/leaflet.css';

  /** Egyetlen betöltés fut, akárhányszor kérik — a további hívók ugyanarra várnak. */
  private betoltes?: Promise<any>;

  public load(): Promise<any> {
    const meglevo = (window as any).L;
    if (meglevo) {
      return Promise.resolve(meglevo);
    }

    if (!this.betoltes) {
      this.betoltes = this.betolt();
    }
    return this.betoltes;
  }

  private betolt(): Promise<any> {
    return new Promise((resolve, reject) => {
      if (!document.querySelector(`link[href="${LeafletLoaderService.STYLE_URL}"]`)) {
        const css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = LeafletLoaderService.STYLE_URL;
        document.head.appendChild(css);
      }

      const meglevoScript = document.querySelector<HTMLScriptElement>(
        `script[src="${LeafletLoaderService.SCRIPT_URL}"]`);

      const kesz = () => {
        const L = (window as any).L;
        // A script lefutott, de nincs `L`: ez rosszabb, mint a hálózati hiba, mert
        // a hívó egy használhatatlan objektumot kapna. Inkább hibázzunk hangosan.
        L ? resolve(L) : reject(new Error('A Leaflet betöltődött, de nincs window.L.'));
      };

      if (meglevoScript) {
        meglevoScript.addEventListener('load', kesz);
        meglevoScript.addEventListener('error', () => reject(new Error('A Leaflet nem tölthető be.')));
        return;
      }

      const script = document.createElement('script');
      script.src = LeafletLoaderService.SCRIPT_URL;
      script.async = true;
      script.addEventListener('load', kesz);
      script.addEventListener('error', () => {
        // A következő próbálkozás induljon újra: egy elbukott betöltés ne zárja ki
        // örökre a térképet (pl. pillanatnyi hálózati hiba után).
        this.betoltes = undefined;
        reject(new Error('A Leaflet nem tölthető be.'));
      });
      document.head.appendChild(script);
    });
  }
}
