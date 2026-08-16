import {ComponentFixture, TestBed} from '@angular/core/testing';
import {MAT_DIALOG_DATA, MatDialogRef} from '@angular/material/dialog';
import {provideTranslateService} from '@ngx-translate/core';

import {PeriodExclusionDialogComponent, PeriodExclusionDialogData} from './period-exclusion-dialog.component';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * Ez a dialógus arról tájékoztat, hogy egy időszak miséi KIZÁRNAK más időszakokat, vagy
 * őket zárja ki más — vagyis pontosan arról a rétegződésről, ami a #747-ben odáig
 * vezetett, hogy egy mise némán eltűnt a naptárból. Ha a nevek nem jutnak el a
 * felhasználóig, nem tudja, mi történt a miserendjével.
 */
describe('PeriodExclusionDialogComponent', () => {

  let fixture: ComponentFixture<PeriodExclusionDialogComponent>;
  let component: PeriodExclusionDialogComponent;
  let dialogRef: jasmine.SpyObj<MatDialogRef<PeriodExclusionDialogComponent>>;

  const adat: PeriodExclusionDialogData = {
    periodName: 'Nyári szünet',
    recentlyExcludedPeriodNames: ['Egész évben'],
    recentlyExclusionSourcePeriodNames: ['Nyári időszámítás'],
  };

  beforeEach(async () => {
    dialogRef = jasmine.createSpyObj('MatDialogRef', ['close']);

    await TestBed.configureTestingModule({
      imports: [PeriodExclusionDialogComponent],
      providers: [
        provideTranslateService(),
        {provide: MatDialogRef, useValue: dialogRef},
        {provide: MAT_DIALOG_DATA, useValue: adat},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(PeriodExclusionDialogComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('kiírja, melyik időszakról van szó', () => {
    expect(fixture.nativeElement.textContent).toContain('Nyári szünet');
  });

  it('kiírja, mely időszakokat zárja ki', () => {
    expect(fixture.nativeElement.textContent).toContain('Egész évben');
  });

  it('kiírja, mely időszakok zárják ki őt', () => {
    expect(fixture.nativeElement.textContent).toContain('Nyári időszámítás');
  });

  it('a bezárás nem ad vissza értéket — ez csak tájékoztatás', () => {
    component.close();

    expect(dialogRef.close).toHaveBeenCalledWith();
  });
});
