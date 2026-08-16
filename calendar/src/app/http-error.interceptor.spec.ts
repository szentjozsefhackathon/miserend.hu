import {TestBed} from '@angular/core/testing';
import {HTTP_INTERCEPTORS, HttpClient, provideHttpClient, withInterceptorsFromDi} from '@angular/common/http';
import {HttpTestingController, provideHttpClientTesting} from '@angular/common/http/testing';
import {throwError} from 'rxjs';

import {HttpErrorInterceptor} from './http-error.interceptor';
import {MatSnackBarService} from './services/mat-snack-bar.service';

/**
 * Ez az interceptor dönti el, MIT LÁT a felhasználó, amikor a szerver hibázik. A naptár
 * minden kérése átmegy rajta, és a felületen nincs más hibajelzés — ha itt rossz szöveg
 * áll elő, a kezelő nem tudja eldönteni, hogy ő rontott-e el valamit, vagy a szerver.
 *
 * A tesztek egy VALÓDI kérésen keresztül mérnek (interceptor + HttpClient együtt), mert
 * az `intercept()` közvetlen hívása nem mutatná meg, hogy a hibaobjektum tényleg olyan
 * alakban érkezik-e, amilyet a kód vár.
 *
 * Egy hibát is rögzítenek: a HTTP-ág üzenetét korábban felülírta a záró
 * `if (error.message)`, mert a HttpErrorResponse-nak az Angular MINDIG ad `.message`-t.
 */
describe('HttpErrorInterceptor', () => {

  let http: HttpClient;
  let httpMock: HttpTestingController;
  let snackBar: jasmine.SpyObj<MatSnackBarService>;

  beforeEach(() => {
    snackBar = jasmine.createSpyObj('MatSnackBarService', ['error', 'success']);

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptorsFromDi()),
        provideHttpClientTesting(),
        {provide: MatSnackBarService, useValue: snackBar},
        {provide: HTTP_INTERCEPTORS, useClass: HttpErrorInterceptor, multi: true},
      ],
    });

    http = TestBed.inject(HttpClient);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  /** @return a felhasználónak megjelenített szöveg */
  function megjelenitettUzenet(): string {
    return snackBar.error.calls.mostRecent().args[0] as string;
  }

  it('sikeres válasznál nem szól bele', () => {
    let valasz: any;
    http.get('/proba').subscribe(v => valasz = v);
    httpMock.expectOne('/proba').flush({ok: true});

    expect(valasz).toEqual({ok: true});
    expect(snackBar.error).not.toHaveBeenCalled();
  });

  it('a hibát továbbdobja, nem nyeli el', () => {
    let hiba: any = null;
    http.get('/proba').subscribe({next: () => {}, error: e => hiba = e});
    httpMock.expectOne('/proba').flush('hiba', {status: 500, statusText: 'Internal Server Error'});

    expect(hiba)
      .withContext('ha elnyelné, a hívó sikernek hinné a hibát')
      .not.toBeNull();
  });

  /**
   * A javítás lényege. Korábban itt az Angular generikus, angol szövege jelent meg
   * ("Http failure response for /proba: 500 Internal Server Error"), mert a záró
   * `if (error.message)` mindig lefutott.
   */
  it('HTTP-hibánál az állapotkódot és a szerver szövegét mutatja', () => {
    http.get('/proba').subscribe({next: () => {}, error: () => {}});
    httpMock.expectOne('/proba').flush('hiba', {status: 500, statusText: 'Internal Server Error'});

    expect(megjelenitettUzenet())
      .withContext('a HttpErrorResponse .message-e nem írhatja felül a saját szövegünket')
      .toBe('API hiba 500: Internal Server Error');
  });

  it('404-nél is a saját szövegünk megy ki', () => {
    http.get('/nincs').subscribe({next: () => {}, error: () => {}});
    httpMock.expectOne('/nincs').flush('nincs', {status: 404, statusText: 'Not Found'});

    expect(megjelenitettUzenet()).toBe('API hiba 404: Not Found');
  });

  /**
   * Ez az az eset, amiért a `.message` átvétele egyáltalán készült: a
   * HttpTimeoutInterceptor SIMA objektumot dob a saját magyar szövegével.
   */
  it('a timeout-interceptor saját üzenetét átveszi', () => {
    const sajat = {
      status: 408,
      statusText: 'Request Timeout',
      message: 'Az API szerver nem válaszolt időben. Kérjük, ellenőrizze az internet kapcsolatot és próbálja újra később.',
    };

    const interceptor = new HttpErrorInterceptor(snackBar);
    const kovetkezo = {handle: () => throwError(() => sajat)} as any;

    interceptor.intercept({} as any, kovetkezo).subscribe({next: () => {}, error: () => {}});

    expect(megjelenitettUzenet())
      .withContext('sima objektumnál a .message-t igenis át kell venni')
      .toBe(sajat.message);
  });

  /** Üzenet nélküli, ismeretlen alakú hibánál sem maradhat a felhasználó szöveg nélkül. */
  it('ismeretlen hibánál is ad valamilyen üzenetet', () => {
    const interceptor = new HttpErrorInterceptor(snackBar);
    const kovetkezo = {handle: () => throwError(() => ({}))} as any;

    interceptor.intercept({} as any, kovetkezo).subscribe({next: () => {}, error: () => {}});

    expect(megjelenitettUzenet()).toBe('Ismeretlen hiba történt');
  });

  it('hálózati hibánál a kapcsolódásra figyelmeztet', () => {
    http.get('/proba').subscribe({next: () => {}, error: () => {}});
    httpMock.expectOne('/proba').error(new ProgressEvent('network error'), {status: 0, statusText: ''});

    expect(megjelenitettUzenet()).toContain('Nem lehet kapcsolódni');
  });

  it('a hibaüzenet hosszabban látszik az alapértelmezettnél', () => {
    http.get('/proba').subscribe({next: () => {}, error: () => {}});
    httpMock.expectOne('/proba').flush('hiba', {status: 500, statusText: 'Internal Server Error'});

    expect(snackBar.error.calls.mostRecent().args[1])
      .withContext('hibát legyen ideje elolvasni')
      .toBe(6000);
  });
});
