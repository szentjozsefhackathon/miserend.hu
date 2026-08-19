import {ComponentFixture, TestBed} from '@angular/core/testing';
import {MAT_DIALOG_DATA, MatDialogRef} from '@angular/material/dialog';
import {provideTranslateService} from '@ngx-translate/core';

import {CopyPeriodDialogComponent, CopyPeriodDialogData} from './copy-period-dialog.component';
import {PeriodService} from '../../services/period.service';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * Ez a dialógus másolja át egy időszak miséit egy másikba — épp az a művelet, ami a
 * #747-hez vezetett („átmásolom, és az eredeti eltűnik"). Ezért itt nem elég, hogy a
 * komponens létrejön:
 *
 *  - a CÉLIDŐSZAK kötelező. Üres választással a másolás értelmezhetetlen, és ha az
 *    űrlap mégis érvényesnek látszana, a felhasználó a semmibe másolna;
 *  - a keresőmező szűkítsen, kis-nagybetűtől függetlenül — a listában több tucat
 *    időszak van, végiggörgetni használhatatlan.
 */
describe('CopyPeriodDialogComponent', () => {

  let fixture: ComponentFixture<CopyPeriodDialogComponent>;
  let component: CopyPeriodDialogComponent;

  const adat: CopyPeriodDialogData = {
    sourcePeriodId: 1,
    sourcePeriodName: 'Egész évben',
    availablePeriods: [
      {id: 2, name: 'Nyári időszámítás'} as any,
      {id: 3, name: 'Téli időszámítás'} as any,
      {id: 4, name: 'Nyári szünet'} as any,
    ],
    massCount: 5,
  };

  beforeEach(async () => {
    const periodService = jasmine.createSpyObj('PeriodService', ['getGeneratedPeriodsByPeriodId']);
    periodService.getGeneratedPeriodsByPeriodId.and.returnValue([{color: '#123456'}]);

    await TestBed.configureTestingModule({
      imports: [CopyPeriodDialogComponent],
      providers: [
        provideTranslateService(),
        {provide: MatDialogRef, useValue: jasmine.createSpyObj('MatDialogRef', ['close'])},
        {provide: PeriodService, useValue: periodService},
        {provide: MAT_DIALOG_DATA, useValue: adat},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(CopyPeriodDialogComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  /** Célidőszak nélkül a másolás értelmezhetetlen. */
  it('célidőszak nélkül az űrlap érvénytelen', () => {
    expect(component.form.valid).toBeFalse();
  });

  it('célidőszakkal érvényessé válik', () => {
    component.form.get('targetPeriodId')!.setValue(adat.availablePeriods[0]);

    expect(component.form.valid).toBeTrue();
  });

  it('a választható időszakok színt kapnak a megkülönböztetéshez', () => {
    expect(component.availablePeriodsWithColors.length).toBe(3);
    expect(component.availablePeriodsWithColors[0].color).toBe('#123456');
  });

  /**
   * A szűrt lista `startWith('')`-tel indul, tehát az ELSŐ kibocsátás mindig a teljes
   * lista. A gépelés hatását csak az azutáni kibocsátáson lehet mérni — ezért iratkozunk
   * fel előbb, és csak utána állítjuk az értéket.
   */
  const szurtTalalatok = (keresés?: string): string[] => {
    let utolso: {name: string}[] = [];
    const feliratkozas = component.filteredPeriods$.subscribe(lista => utolso = lista);

    if (keresés !== undefined) {
      // A keresőmező KÜLÖN FormControl (`targetPeriodCtr`), nem az űrlapé — az
      // űrlap `targetPeriodId` mezője a kötelezőséget méri, a szűrés ezen a másikon megy.
      component.targetPeriodCtr.setValue(keresés as any);
    }

    feliratkozas.unsubscribe();
    return utolso.map(p => p.name);
  };

  it('üres keresésre minden időszak látszik', () => {
    expect(szurtTalalatok().length).toBe(3);
  });

  /** A szűrés kis-nagybetűtől független — a felhasználó nem az adatbázist gépeli. */
  it('a keresés kis-nagybetűtől függetlenül szűkít', () => {
    expect(szurtTalalatok('nyári')).toEqual(['Nyári időszámítás', 'Nyári szünet']);
  });

  it('nem illeszkedő keresésre üres a lista', () => {
    expect(szurtTalalatok('advent').length).toBe(0);
  });
});
