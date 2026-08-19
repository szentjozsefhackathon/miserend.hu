import {TestBed} from '@angular/core/testing';
import {provideHttpClient} from '@angular/common/http';
import {HttpTestingController, provideHttpClientTesting} from '@angular/common/http/testing';

import {SearchService} from './search.service';
import {MatSnackBarService} from './mat-snack-bar.service';
import {environment} from '../../environments/environment';

/**
 * A keresőfelület szerverkapcsolata: a szűrőlisták betöltése, maga a keresés, és a
 * miseidőpontok újragenerálása.
 *
 * A legtörékenyebb pont a `generateMasses()` paraméterezése. Az évek `years[]` néven,
 * ISMÉTELT paraméterként mennek — nem vesszős listaként —, mert a PHP oldal így látja
 * őket tömbnek. Egy `HttpParams.set()` a sok `append()` helyett csendben az utolsó évre
 * szűkítené a generálást, és ez a hiba a felületen semmilyen hibaüzenetet nem adna:
 * egyszerűen kevesebb év generálódna le, mint amit a kezelő kért.
 *
 * A hibaágat is mérjük: mindhárom művelet szól a felhasználónak ÉS továbbdobja a hibát.
 * Ha csak elnyelné, a hívó sikerként értelmezné.
 */
describe('SearchService', () => {

  let service: SearchService;
  let httpMock: HttpTestingController;
  let snackBar: jasmine.SpyObj<MatSnackBarService>;

  const api = environment.apiUrl;

  beforeEach(() => {
    snackBar = jasmine.createSpyObj('MatSnackBarService', ['error', 'success']);

    TestBed.configureTestingModule({
      providers: [
        SearchService,
        provideHttpClient(),
        provideHttpClientTesting(),
        {provide: MatSnackBarService, useValue: snackBar},
      ],
    });

    service = TestBed.inject(SearchService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  describe('szűrőlisták betöltése', () => {

    it('a search végpontról GET-tel kéri', () => {
      service.getData().subscribe();

      const keres = httpMock.expectOne(`${api}search`);
      expect(keres.request.method).toBe('GET');
      keres.flush({});
    });

    it('hiba esetén szól és továbbdobja', () => {
      let hiba: any = null;
      service.getData().subscribe({next: () => {}, error: e => hiba = e});

      httpMock.expectOne(`${api}search`).flush('nem jó', {status: 500, statusText: 'Server Error'});

      expect(snackBar.error).toHaveBeenCalled();
      expect(hiba).withContext('a hibát tovább kell dobni, nem elnyelni').not.toBeNull();
    });
  });

  describe('keresés', () => {

    it('POST-tal megy, a kifejezés és a templom a törzsben', () => {
      service.search('rorate', 42).subscribe();

      const keres = httpMock.expectOne(`${api}search`);
      expect(keres.request.method).toBe('POST');
      expect(keres.request.body).toEqual({params: {q: 'rorate', templom: 42}});
      keres.flush([]);
    });

    it('üres kifejezéssel is elmegy a kérés', () => {
      service.search('', null).subscribe();

      const keres = httpMock.expectOne(`${api}search`);
      expect(keres.request.body.params.q).toBe('');
      keres.flush([]);
    });

    it('hiba esetén szól és továbbdobja', () => {
      let hiba: any = null;
      service.search('x', 1).subscribe({next: () => {}, error: e => hiba = e});

      httpMock.expectOne(`${api}search`).flush('hiba', {status: 500, statusText: 'Server Error'});

      expect(snackBar.error).toHaveBeenCalled();
      expect(hiba).not.toBeNull();
    });
  });

  describe('generálás', () => {

    it('PUT-tal megy a generate végpontra', () => {
      service.generateMasses([2026], 7).subscribe();

      const keres = httpMock.expectOne(r => r.url === `${api}generate`);
      expect(keres.request.method).toBe('PUT');
      keres.flush({});
    });

    it('a templom tids[] néven megy', () => {
      service.generateMasses([2026], 7).subscribe();

      const keres = httpMock.expectOne(r => r.url === `${api}generate`);
      expect(keres.request.params.getAll('tids[]')).toEqual(['7']);
      keres.flush({});
    });

    /** A lényeg: MINDEN év külön paraméterként, különben csendben kevesebb generálódik. */
    it('minden év külön years[] paraméterként megy', () => {
      service.generateMasses([2025, 2026, 2027], 7).subscribe();

      const keres = httpMock.expectOne(r => r.url === `${api}generate`);
      expect(keres.request.params.getAll('years[]'))
        .withContext('ismételt paraméter kell, nem egyetlen összefűzött érték')
        .toEqual(['2025', '2026', '2027']);
      keres.flush({});
    });

    it('év nélkül nem megy years[] paraméter', () => {
      service.generateMasses([], 7).subscribe();

      const keres = httpMock.expectOne(r => r.url === `${api}generate`);
      expect(keres.request.params.getAll('years[]')).toBeNull();
      keres.flush({});
    });

    it('a törzs üres — az adat a paraméterekben megy', () => {
      service.generateMasses([2026], 7).subscribe();

      const keres = httpMock.expectOne(r => r.url === `${api}generate`);
      expect(keres.request.body).toBeNull();
      keres.flush({});
    });

    it('hiba esetén szól és továbbdobja', () => {
      let hiba: any = null;
      service.generateMasses([2026], 7).subscribe({next: () => {}, error: e => hiba = e});

      httpMock.expectOne(r => r.url === `${api}generate`)
        .flush('hiba', {status: 500, statusText: 'Server Error'});

      expect(snackBar.error).toHaveBeenCalled();
      expect(hiba).not.toBeNull();
    });
  });
});
