import {ComponentFixture, TestBed} from '@angular/core/testing';
import {MAT_DIALOG_DATA, MatDialogRef} from '@angular/material/dialog';
import {provideTranslateService} from '@ngx-translate/core';

import {DeletePeriodDialogComponent, DeletePeriodDialogData} from './delete-period-dialog.component';
import {PeriodService} from '../../services/period.service';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * Ez a dialógus IDŐSZAK TÖRLÉSÉT erősíti meg — visszafordíthatatlan művelet, ami a
 * hozzá tartozó miséket is elviszi. Két dolog számít:
 *
 *  1. A válasz értéke. A hívó `true`-ra töröl; ha a mégse ág is `true`-t adna vissza,
 *     a felhasználó a „Mégse" gombbal törölné a miserendjét.
 *  2. A megjelenített tartomány. Az időszak határa vagy dátum (`03-30 – 10-26`), vagy
 *     MÁSIK IDŐSZAKHOZ kötött (Nagyböjt – Húsvét). A felhasználónak látnia kell,
 *     pontosan mit töröl.
 */
describe('DeletePeriodDialogComponent', () => {

  let dialogRef: jasmine.SpyObj<MatDialogRef<DeletePeriodDialogComponent>>;
  let periodService: jasmine.SpyObj<PeriodService>;

  const letrehoz = async (data: DeletePeriodDialogData): Promise<ComponentFixture<DeletePeriodDialogComponent>> => {
    TestBed.resetTestingModule();
    dialogRef = jasmine.createSpyObj('MatDialogRef', ['close']);
    periodService = jasmine.createSpyObj('PeriodService', ['getPeriodNameById']);
    periodService.getPeriodNameById.and.callFake((id: number) => id === 1 ? 'Nagyböjt' : 'Húsvét');

    await TestBed.configureTestingModule({
      imports: [DeletePeriodDialogComponent],
      providers: [
        provideTranslateService(),
        {provide: MatDialogRef, useValue: dialogRef},
        {provide: PeriodService, useValue: periodService},
        {provide: MAT_DIALOG_DATA, useValue: data},
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(DeletePeriodDialogComponent);
    fixture.detectChanges();
    return fixture;
  };

  const adat = (period: any, generatedPeriods: any[] = []): DeletePeriodDialogData =>
    ({period, generatedPeriods, massCount: 3} as DeletePeriodDialogData);

  it('dátumhatáros időszaknál a dátumokat mutatja', async () => {
    const fixture = await letrehoz(adat({startMonthDay: '03-30', endMonthDay: '10-26'}));

    expect(fixture.componentInstance.periodRangeInfo).toBe('03-30 - 10-26');
  });

  /** A másik időszakhoz kötött határt névvel kell megmutatni, nem azonosítóval. */
  it('időszakhoz kötött határnál a neveket mutatja', async () => {
    const fixture = await letrehoz(adat({startPeriodId: 1, endPeriodId: 2}));

    expect(fixture.componentInstance.periodRangeInfo).toBe('Nagyböjt - Húsvét');
  });

  it('félig kötött határnál csak az ismert végét mutatja', async () => {
    const fixture = await letrehoz(adat({startPeriodId: 1}));

    expect(fixture.componentInstance.periodRangeInfo).toBe('Nagyböjt');
  });

  it('határ nélkül üresen marad, nem talál ki semmit', async () => {
    const fixture = await letrehoz(adat({}));

    expect(fixture.componentInstance.periodRangeInfo).toBe('');
  });

  it('a generált időszak színét átveszi', async () => {
    const fixture = await letrehoz(adat({}, [{color: '#ff0000'}]));

    expect(fixture.componentInstance.periodColor).toBe('#ff0000');
  });

  /** A törlés visszafordíthatatlan: a két gomb NEM adhat ugyanolyan választ. */
  it('a megerősítés igazzal, a mégse hamissal zár', async () => {
    const fixture = await letrehoz(adat({}));

    fixture.componentInstance.onConfirmDelete();
    expect(dialogRef.close).toHaveBeenCalledWith(true);

    fixture.componentInstance.onCancel();
    expect(dialogRef.close).toHaveBeenCalledWith(false);
  });
});
