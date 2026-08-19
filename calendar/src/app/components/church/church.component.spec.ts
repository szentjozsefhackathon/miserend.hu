import {ComponentFixture, TestBed} from '@angular/core/testing';
import {ActivatedRoute} from '@angular/router';
import {provideHttpClient} from '@angular/common/http';
import {HttpTestingController, provideHttpClientTesting} from '@angular/common/http/testing';
import {provideTranslateService} from '@ngx-translate/core';

import {ChurchComponent} from './church.component';
import {SpinnerService} from '../../services/spinner.service';
import {MatSnackBarService} from '../../services/mat-snack-bar.service';
import {environment} from '../../../environments/environment';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * A komponens a miseszerkesztő belépési pontja: az útvonalból kiolvassa a templom
 * azonosítóját, betölti az adatait, és átadja a naptárnak. Két dolog érdemel tesztet:
 *
 *  - a BETÖLTÉS ÚTVONALA — ha elromlik, a szerkesztő üres marad;
 *  - a HIBAÁG — a felhasználó kapjon értelmes üzenetet, ne néma üres lapot. A
 *    komponens ezért hiba esetén is `dataLoaded`-re vált, hogy a sablon meg tudja
 *    jeleníteni a magyarázatot.
 */
describe('ChurchComponent', () => {

  let fixture: ComponentFixture<ChurchComponent>;
  let component: ChurchComponent;
  let httpMock: HttpTestingController;

  const TEMPLOM_ID = 42;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ChurchComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideTranslateService(),
        SpinnerService,
        {provide: MatSnackBarService, useValue: jasmine.createSpyObj('MatSnackBarService', ['error', 'success'])},
        {
          provide: ActivatedRoute,
          useValue: {snapshot: {params: {id: String(TEMPLOM_ID)}, queryParamMap: new Map()}},
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(ChurchComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  });

  it('az útvonalból vett azonosítóval tölti be a templomot', () => {
    fixture.detectChanges();

    const req = httpMock.expectOne(environment.apiUrl + 'church/' + TEMPLOM_ID);
    expect(req.request.method).toBe('GET');
    req.flush({id: TEMPLOM_ID, name: 'Teszt templom', masses: []});
  });

  it('a betöltött miséket azonosító szerint tárolja', () => {
    fixture.detectChanges();

    httpMock.expectOne(environment.apiUrl + 'church/' + TEMPLOM_ID).flush({
      id: TEMPLOM_ID,
      name: 'Teszt templom',
      masses: [{id: 10, churchId: TEMPLOM_ID}, {id: 11, churchId: TEMPLOM_ID}],
    });

    expect(component.masses.size).toBe(2);
    expect(component.masses.get(10)!.churchId).toBe(TEMPLOM_ID);
  });

  it('az érzékelő-eseményeket külön veszi át', () => {
    fixture.detectChanges();

    httpMock.expectOne(environment.apiUrl + 'church/' + TEMPLOM_ID).flush({
      id: TEMPLOM_ID, masses: [], eventsFromSensor: [{id: 'confession_1'}],
    });

    expect(component.sensorEvents.length).toBe(1);
  });

  /**
   * A hibaág a lényeg: a felhasználó ne néma üres lapot kapjon. A `dataLoaded` hibánál
   * is igazra vált, mert a sablon ezen az ágon jeleníti meg a magyarázatot.
   */
  it('hiba esetén magyarázatot ad, nem üres lapot', () => {
    fixture.detectChanges();

    httpMock.expectOne(environment.apiUrl + 'church/' + TEMPLOM_ID)
      .flush('nem elérhető', {status: 500, statusText: 'Server Error'});

    expect(component.loadError).toBeTruthy();
    expect(component.dataLoaded).withContext('a sablonnak meg kell tudnia jeleníteni az üzenetet').toBeTrue();
    expect(component.currentChurch).toBeUndefined();
  });

  it('hiba után nem marad ottfelejtett adat', () => {
    fixture.detectChanges();

    httpMock.expectOne(environment.apiUrl + 'church/' + TEMPLOM_ID)
      .flush('hiba', {status: 500, statusText: 'Server Error'});

    expect(component.masses.size).toBe(0);
    expect(component.sensorEvents.length).toBe(0);
  });
});
