import {ComponentFixture, TestBed} from '@angular/core/testing';
import {ActivatedRoute} from '@angular/router';
import {provideHttpClient} from '@angular/common/http';
import {HttpTestingController, provideHttpClientTesting} from '@angular/common/http/testing';
import {provideTranslateService} from '@ngx-translate/core';
import {of} from 'rxjs';

import {SuggestionsComponent} from './suggestions.component';
import {SpinnerService} from '../../services/spinner.service';
import {MatSnackBarService} from '../../services/mat-snack-bar.service';
import {PeriodService} from '../../services/period.service';
import {environment} from '../../../environments/environment';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * Ez a jóváhagyó felület: itt dönti el a gondnok, hogy egy beküldött javaslat bekerül-e
 * a miserendbe. Két betöltés fut egymás után — előbb a templom jelenlegi miséi, aztán a
 * javaslatok —, mert a felület a KETTŐ KÜLÖNBSÉGÉT mutatja. Ha a sorrend vagy az
 * útvonal elromlik, a kezelő üres különbséget lát, és jóváhagy valamit, amit nem látott.
 *
 * A `hasSuggestion` jelző azt mondja meg, van-e egyáltalán mit jóváhagyni — enélkül a
 * felület üres táblázatot mutatna magyarázat nélkül.
 */
describe('SuggestionsComponent', () => {

  let fixture: ComponentFixture<SuggestionsComponent>;
  let component: SuggestionsComponent;
  let httpMock: HttpTestingController;

  const TEMPLOM_ID = 3;

  beforeEach(async () => {
    const periodService = jasmine.createSpyObj('PeriodService',
      ['getPeriodNameById', 'getGeneratedPeriodsByPeriodId', 'getPeriodById'], {periods$: of([])});

    await TestBed.configureTestingModule({
      imports: [SuggestionsComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideTranslateService(),
        SpinnerService,
        {provide: PeriodService, useValue: periodService},
        {provide: MatSnackBarService, useValue: jasmine.createSpyObj('MatSnackBarService', ['error', 'success'])},
        {
          provide: ActivatedRoute,
          // A komponens a `packageId` query-paraméterből választ kezdő csomagot, ezért a
          // `queryParams` nem hiányozhat a mockból.
          useValue: {snapshot: {params: {id: String(TEMPLOM_ID)}, queryParams: {}, queryParamMap: new Map()}},
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(SuggestionsComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  });

  /** Előbb a jelenlegi állapot, csak utána a javaslatok — a felület a kettő különbsége. */
  const betolt = (csomagok: any[], misek: any[] = [{id: 1, churchId: TEMPLOM_ID}]) => {
    fixture.detectChanges();
    httpMock.expectOne(environment.apiUrl + 'church/' + TEMPLOM_ID)
      .flush({id: TEMPLOM_ID, masses: misek});
    httpMock.expectOne(environment.apiUrl + 'suggestions/church/' + TEMPLOM_ID)
      .flush(csomagok);
  };

  it('előbb a templom miséit tölti be', () => {
    fixture.detectChanges();

    const req = httpMock.expectOne(environment.apiUrl + 'church/' + TEMPLOM_ID);
    expect(req.request.method).toBe('GET');
    req.flush({id: TEMPLOM_ID, masses: []});

    httpMock.expectOne(environment.apiUrl + 'suggestions/church/' + TEMPLOM_ID).flush([]);
  });

  it('a jelenlegi miséket azonosító szerint tárolja', () => {
    betolt([], [{id: 1, churchId: TEMPLOM_ID}, {id: 2, churchId: TEMPLOM_ID}]);

    expect(component.origMasses.size).toBe(2);
    expect(component.origDataLoaded).toBeTrue();
  });

  it('a javaslat-csomagokat átveszi', () => {
    betolt([
      {id: 5, churchId: TEMPLOM_ID, createdAt: '2026-08-01T10:00:00', suggestions: []},
      {id: 6, churchId: TEMPLOM_ID, createdAt: '2026-08-02T10:00:00', suggestions: []},
    ]);

    expect(component.suggestionPackages.length).toBe(2);
  });

  /** A beküldés dátumát a kezelő látja — sztringből Date kell, hogy formázni lehessen. */
  it('a beküldés dátumából Date lesz', () => {
    betolt([{id: 5, churchId: TEMPLOM_ID, createdAt: '2026-08-01T10:00:00', suggestions: []}]);

    expect(component.suggestionPackages[0].createdAt instanceof Date).toBeTrue();
  });

  /** Üres listánál a felület mondja meg, hogy nincs mit jóváhagyni. */
  it('javaslat nélkül jelzi, hogy nincs mit jóváhagyni', () => {
    betolt([]);

    expect(component.hasSuggestion).toBeFalse();
  });

  it('javaslattal marad a jóváhagyó nézet', () => {
    betolt([{id: 5, churchId: TEMPLOM_ID, createdAt: '2026-08-01T10:00:00', suggestions: []}]);

    expect(component.hasSuggestion).toBeTrue();
  });
});
