import {ComponentFixture, TestBed} from '@angular/core/testing';
import {provideTranslateService} from '@ngx-translate/core';
import {of} from 'rxjs';

import {PeriodYearEditorComponent} from './period-year-editor.component';
import {PeriodService} from '../../services/period.service';
import {SpinnerService} from '../../services/spinner.service';
import {MatSnackBarService} from '../../services/mat-snack-bar.service';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * Ez a felület állítja be, hogy egy liturgikus időszak MELYIK ÉVBEN mettől meddig tart
 * — vagyis az egész naptár alapját. A #747 is innen eredt: az időszakok évenkénti
 * tartománya dönti el, melyik mise melyiket fedi le.
 *
 * Amit rögzítünk: az évenkénti sorok az időszakok NEVÉVEL együtt épülnek fel (számmal a
 * kezelő nem tud mit kezdeni), és az ismeretlen időszakra hivatkozó sor kimarad, nem
 * dönti el az egész szerkesztőt.
 */
describe('PeriodYearEditorComponent', () => {

  let component: PeriodYearEditorComponent;
  let periodService: jasmine.SpyObj<PeriodService>;

  const idoszakok = [
    {id: 1, name: 'Nagyböjt', multiDay: true},
    {id: 2, name: 'Advent', multiDay: true},
  ];

  const letrehoz = async (periodsYear: any[]): Promise<ComponentFixture<PeriodYearEditorComponent>> => {
    TestBed.resetTestingModule();

    periodService = jasmine.createSpyObj('PeriodService', ['getPeriodsYear', 'saveData', 'generatePeriods'], {
      periods$: of(idoszakok as any),
    });
    periodService.getPeriodsYear.and.returnValue(of(periodsYear as any));

    await TestBed.configureTestingModule({
      imports: [PeriodYearEditorComponent],
      providers: [
        provideTranslateService(),
        {provide: PeriodService, useValue: periodService},
        SpinnerService,
        {provide: MatSnackBarService, useValue: jasmine.createSpyObj('MatSnackBarService', ['error', 'success'])},
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(PeriodYearEditorComponent);
    component = fixture.componentInstance;
    return fixture;
  };

  it('betölti az időszakokat azonosító szerint', async () => {
    await letrehoz([]);

    expect(component.periods.size).toBe(2);
    expect(component.periods.get(1)!.name).toBe('Nagyböjt');
  });

  it('évenként csoportosítja a sorokat', async () => {
    await letrehoz([
      {id: 10, periodId: 1, startYear: 2026, startDate: '2026-02-18', endDate: '2026-04-02'},
      {id: 11, periodId: 2, startYear: 2026, startDate: '2026-11-29', endDate: '2026-12-24'},
      {id: 12, periodId: 1, startYear: 2027, startDate: '2027-02-10', endDate: '2027-03-25'},
    ]);

    expect(component.periodsWrapperMap.get(2026)!.periodYearEdits.length).toBe(2);
    expect(component.periodsWrapperMap.get(2027)!.periodYearEdits.length).toBe(1);
  });

  /** Számmal a kezelő nem tud mit kezdeni — a sorhoz oda kell a név. */
  it('a sorok az időszak nevét is hordozzák', async () => {
    await letrehoz([{id: 10, periodId: 1, startYear: 2026, startDate: '2026-02-18', endDate: '2026-04-02'}]);

    expect(component.periodsWrapperMap.get(2026)!.periodYearEdits[0].periodName).toBe('Nagyböjt');
  });

  it('a dátumokból Date lesz, hogy a naptárválasztó kezelni tudja', async () => {
    await letrehoz([{id: 10, periodId: 1, startYear: 2026, startDate: '2026-02-18', endDate: '2026-04-02'}]);

    const sor = component.periodsWrapperMap.get(2026)!.periodYearEdits[0];
    expect(sor.startDate instanceof Date).toBeTrue();
    expect(sor.endDate instanceof Date).toBeTrue();
  });

  it('a hiányzó dátum null marad, nem lesz belőle érvénytelen Date', async () => {
    await letrehoz([{id: 10, periodId: 1, startYear: 2026, startDate: null, endDate: null}]);

    const sor = component.periodsWrapperMap.get(2026)!.periodYearEdits[0];
    expect(sor.startDate).toBeNull();
    expect(sor.endDate).toBeNull();
  });

  /** Ismeretlen időszakra hivatkozó sor kimarad — nem viszi el az egész szerkesztőt. */
  it('ismeretlen időszakra hivatkozó sort kihagy', async () => {
    await letrehoz([
      {id: 10, periodId: 1, startYear: 2026, startDate: '2026-02-18', endDate: '2026-04-02'},
      {id: 11, periodId: 999, startYear: 2026, startDate: '2026-01-01', endDate: '2026-01-02'},
    ]);

    expect(component.periodsWrapperMap.get(2026)!.periodYearEdits.length).toBe(1);
  });

  it('induláskor nincs mentetlen változás', async () => {
    await letrehoz([]);

    expect(component.changed).toBeFalse();
  });
});
