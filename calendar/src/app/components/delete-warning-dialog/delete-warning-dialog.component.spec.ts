import {ComponentFixture, TestBed} from '@angular/core/testing';
import {MAT_DIALOG_DATA, MatDialogRef} from '@angular/material/dialog';
import {provideTranslateService} from '@ngx-translate/core';

import {DeleteWarningDialogComponent} from './delete-warning-dialog.component';
import {DialogResponse} from '../../enum/dialog-response';
import {PeriodService} from '../../services/period.service';
import {Mass} from '../../model/mass';
import {Rite} from '../../enum/rites';
import {SpecialType} from '../../model/period';
import {Day} from '../../enum/day';

function makeMass(overrides: Partial<Mass> = {}): Mass {
  return {
    id: 1,
    churchId: 100,
    title: 'Szentmise',
    rite: Rite.ROMAN_CATHOLIC,
    startDate: '2026-03-15T10:00:00',
    lang: 'hu',
    ...overrides,
  };
}

describe('DeleteWarningDialogComponent', () => {
  let component: DeleteWarningDialogComponent;
  let fixture: ComponentFixture<DeleteWarningDialogComponent>;
  let dialogRefMock: { close: jasmine.Spy };
  let periodServiceMock: { getPeriodById: jasmine.Spy; getSpecialPeriodType: jasmine.Spy };

  async function setup(mass: Mass, periodOverrides: any = null, specialType: SpecialType | null = null) {
    dialogRefMock = {close: jasmine.createSpy('close')};
    periodServiceMock = {
      getPeriodById: jasmine.createSpy().and.returnValue(periodOverrides),
      getSpecialPeriodType: jasmine.createSpy().and.returnValue(specialType),
    };

    const dialogData = {
      eventData: {
        churchName: 'Teszt Templom',
        mass,
        suggestOrEditable: true,
        start: new Date('2026-03-15T10:00:00'),
      },
      deleteOne: false,
    };

    await TestBed.configureTestingModule({
      imports: [DeleteWarningDialogComponent],
      providers: [
        provideTranslateService(),
        {provide: MAT_DIALOG_DATA, useValue: dialogData},
        {provide: MatDialogRef, useValue: dialogRefMock},
        {provide: PeriodService, useValue: periodServiceMock},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(DeleteWarningDialogComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  }

  it('onContinue() closes the dialog with CONTINUE response', async () => {
    await setup(makeMass({periodId: 10}), {name: 'Évközi'});
    component.onContinue();
    expect(dialogRefMock.close).toHaveBeenCalledWith(DialogResponse.CONTINUE);
  });

  it('period getter returns the period name from PeriodService', async () => {
    await setup(makeMass({periodId: 10}), {name: 'Iskolaidő', id: 10});
    expect(component.period).toBe('Iskolaidő');
  });

  it('period getter returns empty string when PeriodService has no period', async () => {
    await setup(makeMass({periodId: 999}), null);
    expect(component.period).toBe('');
  });

  it('week getter returns null for non-weekly recurrence', async () => {
    await setup(makeMass({
      periodId: 10,
      rrule: {dtstart: '2026-03-15', freq: 'monthly'},
    }), {name: 'X'});
    expect(component.week).toBeNull();
  });

  it('week getter returns "every week" translation for weekly with no byweekno', async () => {
    await setup(makeMass({
      periodId: 10,
      rrule: {dtstart: '2026-03-15', freq: 'weekly', byweekday: [Day.MO]},
    }), {name: 'X'});
    expect(component.week).toBe('RRULE.ON.EVERY_WEEK');
  });

  it('week getter returns EVEN translation when all byweekno are even', async () => {
    await setup(makeMass({
      periodId: 10,
      rrule: {dtstart: '2026-03-15', freq: 'weekly', byweekno: [2, 4, 6, 8]},
    }), {name: 'X'});
    expect(component.week).toBe('RRULE.ON.EVEN');
  });

  it('easter getter returns null when periodId is not an Easter period', async () => {
    await setup(makeMass({periodId: 10}), {name: 'X'}, null);
    expect(component.easter).toBeNull();
  });

  it('christmas getter returns translation for Christmas period + rrule.bymonth=12', async () => {
    await setup(
      makeMass({
        periodId: 50,
        rrule: {dtstart: '2026-12-25', freq: 'yearly', bymonth: 12, bymonthday: [25]},
      }),
      {name: 'Karácsony'},
      SpecialType.CHRISTMAS,
    );
    // a translation key formátum: "CHRISTMAS_DAYS.<dayKey>" - a MassUtil eldönti melyik
    // a key konkrétan. nekünk az kell hogy NEM null, és tartalmazza a "CHRISTMAS_DAYS." prefixet.
    const result = component.christmas;
    expect(result).not.toBeNull();
    expect(result).toContain('CHRISTMAS_DAYS.');
  });
});
