import {Injectable} from '@angular/core';

/**
 * #816: a Leaflet betöltése FUTÁSIDŐBEN, nem a csomagba fordítva.
 *
 * A miserend.hu úgyis kiszolgálja a Leafletet a saját `node_modules`-ából (#661/#653),
 * és a templomoldal be is tölti a helyszín-panelhez — ott tehát a `window.L` már kész
 * van, mire a szerkesztő megnyílik. Ha nincs, innen töltjük be, ugyanarról a hosztról
 * és ugyanabban a verzióban.
 *
 * Miért nem npm-függőség a naptárban? Mert akkor KÉT Leaflet-példány lenne az oldalon,
 * két verzióval, és ~150 KB-tal nagyobb csomag egy olyan funkcióért, amit a szerkesztők
 * töredéke használ. A #661 pont azt a rendetlenséget takarította el, hogy három sablon
 * három Leaflet-verziót húzott be.
 *
 * A STÍLUSLAP ugyanolyan fontos, mint a script — sőt. A Leaflet a csempéket a
 * `leaflet.css`-ből kapott `position: absolute`-tal rakja a helyükre, a térkép dobozát
 * pedig az `overflow: hidden` tartja egyben. Enélkül a csempék KISZÖKNEK a dobozból, és
 * szétszóródnak az űrlapon — borazslo pontosan ezt kapta: „össze-vissza ugrál és
 * elmászkál". Ezért:
 *
 *   1. a stíluslapot akkor is beszúrjuk, ha a `window.L` már megvan (a két dolog
 *      külön-külön is hiányozhat: az `editschedule` oldal például egyiket sem tölti be,
 *      a templomoldal meg csak akkor, ha a templomnak van koordinátája);
 *   2. és MEGVÁRJUK a betöltését, mielőtt visszaadjuk a `L`-t — különben a térkép
 *      stílus nélküli DOM-ra épülne fel.
 */
@Injectable({providedIn: 'root'})
export class LeafletLoaderService {

  private static readonly SCRIPT_URL = '/node_modules/leaflet/dist/leaflet.js';
  private static readonly STYLE_URL = '/node_modules/leaflet/dist/leaflet.css';

  /** Egyetlen betöltés fut, akárhányszor kérik — a további hívók ugyanarra várnak. */
  private betoltes?: Promise<any>;

  /**
   * @param gyoker AHOVA a stíluslap kerül. A naptár `ViewEncapsulation.ShadowDom`-mal fut
   *               (`app.component.ts`), és a shadow DOM ELSZIGETELI a globális CSS-t —
   *               a `document.head`-be szúrt `leaflet.css` tehát SOSEM ért el a
   *               térképig. A hívó a saját elemének `getRootNode()`-jával adja meg a
   *               valódi gyökeret; l. `LocationPickerComponent`.
   */
  public load(gyoker?: Document | ShadowRoot): Promise<any> {
    if (!this.betoltes) {
      this.betoltes = this.betolt(gyoker);
    } else if (gyoker && gyoker !== document) {
      /*
       * A script csak egyszer töltődik be, a STÍLUSLAP viszont gyökerenként kell.
       * Ha egy második komponens másik shadow rootban nyílik meg, annak is jár —
       * enélkül a másodikban megint szétcsúsznának a csempék.
       */
      this.stilust(gyoker);
    }
    return this.betoltes;
  }

  private async betolt(gyoker?: Document | ShadowRoot): Promise<any> {
    // A stíluslap előbb: a scriptre várás alatt legalább letöltődik.
    const stilus = this.stilust(gyoker ?? document);
    const L = (window as any).L ? (window as any).L : await this.scriptet();

    await stilus;

    if (!L) {
      throw new Error('A Leaflet betöltődött, de nincs window.L.');
    }
    return L;
  }

  /**
   * A stíluslap betöltése, a `load`/`error` eseményre várva.
   *
   * Sosem utasít el: ha a stíluslap nem jön meg, a térkép csúnya lesz, de a szerkesztő
   * ne dőljön el tőle. A dobozból kiszökő csempék ellen a komponens saját CSS-e is véd
   * (`overflow: hidden`), tehát a kár ilyenkor is korlátozott.
   */
  private stilust(gyoker: Document | ShadowRoot = document): Promise<void> {
    /*
     * #816: a stíluslap ABBA a gyökérbe kell, ahol a térkép él.
     *
     * A naptár `ViewEncapsulation.ShadowDom`-mal fut, a shadow DOM pedig elszigeteli a
     * globális CSS-t. A `document.head`-be szúrt `leaflet.css` tehát letöltődött (a
     * Network fülön látszott is), de a térképre SOSEM vonatkozott — a Leaflet által
     * `createElement()`-tel gyártott csempék nem kapták meg a `position: absolute`-ot,
     * és a `transform: translate3d()` mindegyikre MÁS kiindulóponthoz adódott hozzá.
     * Innen a diagonálisan szétcsúszott mintázat.
     */
    const meglevo = gyoker.querySelector<HTMLLinkElement>(
      `link[href="${LeafletLoaderService.STYLE_URL}"]`);

    if (meglevo) {
      // Már betöltött stíluslapnál a `load` esemény SOHA nem jön el újra — a
      // `sheet` megléte mondja meg, hogy kész van-e.
      if (meglevo.sheet) {
        return Promise.resolve();
      }
      return this.esemenyre(meglevo);
    }

    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = LeafletLoaderService.STYLE_URL;

    // A `document`-nél a `head`, shadow rootnál maga a gyökér az illesztési pont.
    const cel: Node = gyoker instanceof ShadowRoot ? gyoker : document.head;
    cel.appendChild(css);

    return this.esemenyre(css);
  }

  private scriptet(): Promise<any> {
    return new Promise((resolve, reject) => {
      const kesz = () => resolve((window as any).L);

      const meglevo = document.querySelector<HTMLScriptElement>(
        `script[src="${LeafletLoaderService.SCRIPT_URL}"]`);
      if (meglevo) {
        meglevo.addEventListener('load', kesz);
        meglevo.addEventListener('error', () => reject(new Error('A Leaflet nem tölthető be.')));
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

  private esemenyre(elem: HTMLElement): Promise<void> {
    return new Promise(resolve => {
      elem.addEventListener('load', () => resolve());
      elem.addEventListener('error', () => resolve());
    });
  }
}
