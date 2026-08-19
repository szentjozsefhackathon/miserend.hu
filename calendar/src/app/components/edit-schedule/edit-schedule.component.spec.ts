import {ComponentFixture, TestBed} from '@angular/core/testing';
import {ActivatedRoute} from '@angular/router';
import {provideHttpClient} from '@angular/common/http';
import {HttpTestingController, provideHttpClientTesting} from '@angular/common/http/testing';
import {provideTranslateService} from '@ngx-translate/core';

import {EditScheduleComponent} from './edit-schedule.component';
import {SpinnerService} from '../../services/spinner.service';
import {MatSnackBarService} from '../../services/mat-snack-bar.service';
import {environment} from '../../../environments/environment';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * Ez a szerkesztő gondnoki nézete — ugyanazt a templomot tölti be, mint a nyilvános
 * naptár, csak szerkeszthetően. Amit rögzítünk: a betöltés útvonala és az, hogy a
 * misék azonosító szerint, hiánytalanul kerülnek a modellbe. Ha ez elromlik, a gondnok
 * üres miserendet lát, és jóhiszeműen újra felviszi az egészet.
 */
describe('EditScheduleComponent', () => {

  let fixture: ComponentFixture<EditScheduleComponent>;
  let component: EditScheduleComponent;
  let httpMock: HttpTestingController;

  const TEMPLOM_ID = 7;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [EditScheduleComponent],
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

    fixture = TestBed.createComponent(EditScheduleComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  });

  it('az útvonalból vett azonosítóval tölt be', () => {
    fixture.detectChanges();

    const req = httpMock.expectOne(environment.apiUrl + 'church/' + TEMPLOM_ID);
    expect(req.request.method).toBe('GET');
    req.flush({id: TEMPLOM_ID, masses: []});
  });

  it('a miséket azonosító szerint tárolja', () => {
    fixture.detectChanges();

    httpMock.expectOne(environment.apiUrl + 'church/' + TEMPLOM_ID).flush({
      id: TEMPLOM_ID,
      masses: [{id: 1, churchId: TEMPLOM_ID}, {id: 2, churchId: TEMPLOM_ID}, {id: 3, churchId: TEMPLOM_ID}],
    });

    expect(component.masses.size).toBe(3);
    expect(component.dataLoaded).toBeTrue();
  });

  it('mise nélküli templomot is betölt', () => {
    fixture.detectChanges();

    httpMock.expectOne(environment.apiUrl + 'church/' + TEMPLOM_ID).flush({id: TEMPLOM_ID, masses: []});

    expect(component.masses.size).toBe(0);
    expect(component.currentChurch).toBeDefined();
  });
});
