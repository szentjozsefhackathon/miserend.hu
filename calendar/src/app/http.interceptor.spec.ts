import {fakeAsync, tick} from '@angular/core/testing';
import {HttpErrorResponse, HttpResponse} from '@angular/common/http';
import {NEVER, of, throwError} from 'rxjs';

import {HttpTimeoutInterceptor} from './http.interceptor';

/**
 * Ez az interceptor a naptár védelme a néma szerver ellen: ha 15 másodpercig nem jön
 * válasz, elvágja a kérést. Nélküle a felület örökre pörgő töltésjelzőn állna, és a
 * kezelő nem tudná, hogy várjon-e még vagy töltse újra.
 *
 * A kifelé adott hibaalak SZERZŐDÉS: a HttpErrorInterceptor épp ezekre a mezőkre
 * támaszkodik (`status`, `statusText`, `message`), amikor eldönti, mit írjon ki. Ha itt
 * elcsúszik az alak, a felhasználó "Ismeretlen hiba" szöveget kap a pontos magyarázat
 * helyett — a két fájl külön él, ezt csak teszt köti össze.
 *
 * A méréshez közvetlenül az `intercept()`-et hajtjuk meg egy vezérelhető `next`-tel:
 * a 15 másodpercet így lehet pontosan kivárni, valódi HTTP-háttér nélkül.
 */
describe('HttpTimeoutInterceptor', () => {

  let interceptor: HttpTimeoutInterceptor;

  /** @param forras amit a lánc következő eleme ad vissza */
  function futtat(forras: any): {ertek: any; hiba: any} {
    const eredmeny: {ertek: any; hiba: any} = {ertek: undefined, hiba: null};
    interceptor
      .intercept({} as any, {handle: () => forras} as any)
      .subscribe({next: v => eredmeny.ertek = v, error: e => eredmeny.hiba = e});
    return eredmeny;
  }

  beforeEach(() => {
    interceptor = new HttpTimeoutInterceptor();
  });

  it('a sikeres választ változatlanul engedi át', () => {
    const valasz = new HttpResponse({status: 200, body: {ok: true}});
    const eredmeny = futtat(of(valasz));

    expect(eredmeny.ertek).toBe(valasz);
    expect(eredmeny.hiba).toBeNull();
  });

  it('a 15 másodpercen belül érkező válasz átmegy', fakeAsync(() => {
    const eredmeny = futtat(of(new HttpResponse({status: 200})));
    tick(14999);

    expect(eredmeny.hiba).toBeNull();
    expect(eredmeny.ertek).toBeDefined();
  }));

  it('15 másodperc után elvágja a néma kérést', fakeAsync(() => {
    const eredmeny = futtat(NEVER);

    tick(14999);
    expect(eredmeny.hiba)
      .withContext('a határidő előtt még várni kell')
      .toBeNull();

    tick(2);
    expect(eredmeny.hiba).not.toBeNull();
  }));

  /** A HttpErrorInterceptor pontosan ezekre a mezőkre épít. */
  it('a timeout 408-as alakban megy tovább, magyar üzenettel', fakeAsync(() => {
    const eredmeny = futtat(NEVER);
    tick(15001);

    expect(eredmeny.hiba.status).toBe(408);
    expect(eredmeny.hiba.statusText).toBe('Request Timeout');
    expect(eredmeny.hiba.message).toContain('nem válaszolt időben');
  }));

  it('hálózati hibából saját, magyarázó alakot csinál', () => {
    const halozati = new HttpErrorResponse({status: 0, statusText: 'Unknown Error'});
    const eredmeny = futtat(throwError(() => halozati));

    expect(eredmeny.hiba.status).toBe(0);
    expect(eredmeny.hiba.statusText).toBe('Network Error');
    expect(eredmeny.hiba.message).toContain('Nem lehet kapcsolódni');
  });

  /**
   * A valódi HTTP-hibát (404, 500) NEM alakítja át — azt a HttpErrorInterceptor
   * fordítja le, mert csak ott ismerjük az állapotkódot és a szerver szövegét.
   */
  it('a valódi HTTP-hibát változatlanul dobja tovább', () => {
    const szerverHiba = new HttpErrorResponse({status: 500, statusText: 'Internal Server Error'});
    const eredmeny = futtat(throwError(() => szerverHiba));

    expect(eredmeny.hiba)
      .withContext('nem szabad átcsomagolni, különben elvész az állapotkód')
      .toBe(szerverHiba);
  });

  it('a 404-et is érintetlenül hagyja', () => {
    const nincs = new HttpErrorResponse({status: 404, statusText: 'Not Found'});
    expect(futtat(throwError(() => nincs)).hiba).toBe(nincs);
  });

  it('az ismeretlen alakú hibát is továbbdobja', () => {
    const ismeretlen = {valami: 'más'};
    expect(futtat(throwError(() => ismeretlen)).hiba).toBe(ismeretlen);
  });
});
