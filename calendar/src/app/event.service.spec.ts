import {TestBed} from '@angular/core/testing';
import {provideHttpClient} from '@angular/common/http';
import {HttpTestingController, provideHttpClientTesting} from '@angular/common/http/testing';

import {EventService} from './event.service';
import {MatSnackBarService} from './services/mat-snack-bar.service';
import {environment} from '../environments/environment';
import {SuggestionPackage, SuggestionState} from './model/suggestion-package';
import {Mass} from './model/mass';

/**
 * #436: ez a fájl egy CLI-generált csonk volt (`should be created`), `xdescribe`-bal
 * kikapcsolva, mert nem voltak hozzá DI-providerek.
 *
 * A szolgáltatás a naptár EGYETLEN kapcsolata a szerverrel: minden betöltés, mentés,
 * javaslat-beküldés és jóváhagyás rajta megy át. Amit itt érdemes rögzíteni, az nem a
 * példányosíthatóság, hanem a SZERZŐDÉS: milyen URL-re, milyen metódussal és milyen
 * törzzsel megyünk — mert ezt a backend oldaláról senki nem látja, és egy elgépelt
 * útvonal csendben 404-et hoz, amit a felhasználó „nem sikerült menteni"-ként él meg.
 *
 * A hibaágat is mérjük: a szolgáltatás minden hívásnál üzenetet küld a felhasználónak,
 * és TOVÁBBDOBJA a hibát. Ha csak elnyelné, a hívó azt hinné, sikerült.
 */
describe('EventService', () => {

  let service: EventService;
  let httpMock: HttpTestingController;
  let snackBar: jasmine.SpyObj<MatSnackBarService>;

  const api = environment.apiUrl;

  beforeEach(() => {
    snackBar = jasmine.createSpyObj('MatSnackBarService', ['error', 'success']);

    TestBed.configureTestingModule({
      providers: [
        EventService,
        provideHttpClient(),
        provideHttpClientTesting(),
        {provide: MatSnackBarService, useValue: snackBar},
      ],
    });

    service = TestBed.inject(EventService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    // Kiadatlan kérés = a szolgáltatás mást hívott, mint amit a teszt vár.
    httpMock.verify();
  });

  describe('templom betöltése', () => {

    it('a templom azonosítójával kéri le az adatokat', () => {
      service.getChurch(42).subscribe();

      const req = httpMock.expectOne(api + 'church/42');
      expect(req.request.method).toBe('GET');
      req.flush({id: 42, masses: []});
    });

    it('hiba esetén szól a felhasználónak ÉS továbbdobja a hibát', () => {
      let kapottHiba = false;
      service.getChurch(42).subscribe({error: () => kapottHiba = true});

      httpMock.expectOne(api + 'church/42').flush('nem elérhető', {status: 500, statusText: 'Server Error'});

      expect(snackBar.error).toHaveBeenCalled();
      expect(kapottHiba).withContext('a hívónak tudnia kell, hogy elhasalt').toBeTrue();
    });
  });

  describe('mentés', () => {

    const mise = (id: number): Mass => ({id, churchId: 1, title: 'Szentmise'} as Mass);

    it('a templom azonosítójára POST-ol', () => {
      service.saveChanges(7, [mise(1)], [2, 3]).subscribe();

      const req = httpMock.expectOne(api + 'masses/7');
      expect(req.request.method).toBe('POST');
      req.flush([]);
    });

    /**
     * A törzs alakja a backend szerződése: a `masses` és a `deletedMasses` kulcs
     * nevén múlik, hogy a mentés egyáltalán megtörténik-e.
     */
    it('a törzsben a módosított és a törölt misék külön kulcson mennek', () => {
      service.saveChanges(7, [mise(1), mise(2)], [9]).subscribe();

      const req = httpMock.expectOne(api + 'masses/7');
      expect(req.request.body.masses.length).toBe(2);
      expect(req.request.body.deletedMasses).toEqual([9]);
      req.flush([]);
    });

    it('üres változtatással is elmegy a kérés', () => {
      service.saveChanges(7, [], []).subscribe();

      const req = httpMock.expectOne(api + 'masses/7');
      expect(req.request.body.masses).toEqual([]);
      expect(req.request.body.deletedMasses).toEqual([]);
      req.flush([]);
    });

    it('hiba esetén szól és továbbdobja', () => {
      let kapottHiba = false;
      service.saveChanges(7, [], []).subscribe({error: () => kapottHiba = true});

      httpMock.expectOne(api + 'masses/7').flush('hiba', {status: 403, statusText: 'Forbidden'});

      expect(snackBar.error).toHaveBeenCalled();
      expect(kapottHiba).toBeTrue();
    });
  });

  describe('javaslatok', () => {

    const csomag = (id: number): SuggestionPackage => ({id, churchId: 1, suggestions: []} as unknown as SuggestionPackage);

    it('beküldés a templom javaslat-útvonalára megy', () => {
      service.sendToApprove(5, csomag(0)).subscribe();

      const req = httpMock.expectOne(api + 'suggestions/church/5');
      expect(req.request.method).toBe('POST');
      req.flush({success: true});
    });

    it('állapot nélkül a teljes listát kéri', () => {
      service.getSuggestions(5).subscribe();

      const req = httpMock.expectOne(api + 'suggestions/church/5');
      expect(req.request.method).toBe('GET');
      req.flush([]);
    });

    it('állapottal szűkítve az útvonal végére kerül a szűrő', () => {
      service.getSuggestions(5, SuggestionState.PENDING).subscribe();

      httpMock.expectOne(api + 'suggestions/church/5/' + SuggestionState.PENDING).flush([]);
    });

    it('elfogadásnál az állapot a törzsben megy', () => {
      service.simpleAcceptSuggestionPackage(csomag(11)).subscribe();

      const req = httpMock.expectOne(api + 'suggestions/accept/11');
      expect(req.request.method).toBe('POST');
      expect(req.request.body.state).toBe(SuggestionState.ACCEPTED);
      req.flush({suggestionPackages: [], calendarMasses: []});
    });

    /**
     * #543: az elutasító levél a beküldőnek CSAK akkor megy ki, ha a kezelő kérte.
     * Az alapértelmezés `false` — ezt itt is rögzítjük, mert egy néma `true` alapérték
     * miatt minden téves beküldésre levelet kapna a beküldő.
     */
    it('elutasításnál alapból NEM értesítjük a beküldőt', () => {
      service.simpleRejectSuggestionPackage(csomag(12)).subscribe();

      const req = httpMock.expectOne(api + 'suggestions/reject/12');
      expect(req.request.body.state).toBe(SuggestionState.REJECTED);
      expect(req.request.body.notify_sender).toBeFalse();
      req.flush({suggestionPackages: [], calendarMasses: []});
    });

    it('elutasításnál kérésre értesítjük a beküldőt', () => {
      service.simpleRejectSuggestionPackage(csomag(12), true).subscribe();

      const req = httpMock.expectOne(api + 'suggestions/reject/12');
      expect(req.request.body.notify_sender).toBeTrue();
      req.flush({suggestionPackages: [], calendarMasses: []});
    });
  });

  describe('liturgikus napok', () => {

    it('a két dátum query-paraméterként megy', () => {
      service.getLiturgicalDays('2026-01-01', '2026-12-31').subscribe();

      const req = httpMock.expectOne(r => r.url === api + 'liturgicaldays');
      expect(req.request.params.get('from')).toBe('2026-01-01');
      expect(req.request.params.get('until')).toBe('2026-12-31');
      req.flush({});
    });
  });
});
