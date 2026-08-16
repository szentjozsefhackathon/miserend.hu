import {ComponentFixture, TestBed} from '@angular/core/testing';
import {MAT_DIALOG_DATA, MatDialog, MatDialogRef} from '@angular/material/dialog';
import {provideTranslateService} from '@ngx-translate/core';
import {of} from 'rxjs';

import {EventViewerDialogComponent} from './event-viewer-dialog.component';
import {EventViewerDialogData} from '../church-calendar/church-calendar.component';
import {PeriodService} from '../../services/period.service';
import {EditConfirmationService} from '../../services/edit-confirmation.service';
import {DialogResponse} from '../../enum/dialog-response';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * Ez az ablak nyílik meg egy miséhez a naptárban, és innen indul a szerkesztés meg a
 * TÖRLÉS is. A válaszkódok szerződése kritikus: a hívó ezek alapján dönti el, hogy egy
 * alkalmat vagy az EGÉSZ sorozatot törli-e. Egy felcserélt válasz nem hibaüzenetet ad,
 * hanem csendben törli az egész miserendet.
 *
 * A szerkesztés előtti megerősítést egy megosztott szolgáltatás jegyzi meg: ha a
 * felhasználó egyszer rábólintott, ne kérdezzük újra minden misénél.
 */
describe('EventViewerDialogComponent', () => {

  let fixture: ComponentFixture<EventViewerDialogComponent>;
  let component: EventViewerDialogComponent;
  let dialogRef: jasmine.SpyObj<MatDialogRef<EventViewerDialogComponent>>;
  let dialog: jasmine.SpyObj<MatDialog>;
  let editConfirmation: jasmine.SpyObj<EditConfirmationService>;

  const adat: EventViewerDialogData = {
    churchName: 'Teszt templom',
    mass: {id: 1, churchId: 1, title: 'Szentmise'} as any,
    suggestOrEditable: true,
    start: new Date('2026-08-16T09:00:00'),
  };

  const valaszol = (valasz: unknown) => dialog.open.and.returnValue({afterClosed: () => of(valasz)} as any);

  beforeEach(async () => {
    dialogRef = jasmine.createSpyObj('MatDialogRef', ['close']);
    dialog = jasmine.createSpyObj('MatDialog', ['open']);
    editConfirmation = jasmine.createSpyObj('EditConfirmationService', ['isConfirmed', 'confirm', 'getMessage']);
    editConfirmation.getMessage.and.returnValue('Biztos?');
    editConfirmation.isConfirmed.and.returnValue(false);

    await TestBed.configureTestingModule({
      imports: [EventViewerDialogComponent],
      providers: [
        provideTranslateService(),
        {provide: MatDialogRef, useValue: dialogRef},
        {provide: MatDialog, useValue: dialog},
        {provide: PeriodService, useValue: jasmine.createSpyObj('PeriodService', ['getPeriodNameById', 'getGeneratedPeriodsByPeriodId'])},
        {provide: EditConfirmationService, useValue: editConfirmation},
        {provide: MAT_DIALOG_DATA, useValue: adat},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(EventViewerDialogComponent);
    component = fixture.componentInstance;
  });

  it('a mise kezdetét olvasható alakra hozza', () => {
    expect(component.start).toBeTruthy();
    expect(typeof component.start).toBe('string');
  });

  /**
   * A két törlés-válasz NEM cserélhető fel: az egyik egy alkalmat visz el, a másik az
   * egész sorozatot.
   */
  it('egy alkalom törlése DELETE_ONE választ ad', () => {
    valaszol(DialogResponse.CONTINUE);

    component.onDeleteMassOne();

    expect(dialogRef.close).toHaveBeenCalledWith(DialogResponse.DELETE_ONE);
  });

  it('a sorozat törlése DELETE_ALL választ ad', () => {
    valaszol(DialogResponse.CONTINUE);

    component.onDeleteMassAll();

    expect(dialogRef.close).toHaveBeenCalledWith(DialogResponse.DELETE_ALL);
  });

  /** Megerősítés nélkül semmit nem törlünk. */
  it('elutasított megerősítés esetén nem törlünk', () => {
    valaszol('MEGSE');

    component.onDeleteMassOne();

    expect(dialogRef.close).not.toHaveBeenCalledWith(DialogResponse.DELETE_ONE);
  });

  /** A szerkesztés két hatóköre is külön válasz — ezek sem cserélhetők fel. */
  it('a sorozat szerkesztése EVENT_VIEWER_EDIT_ALL választ ad', () => {
    component.onEditMassAll();

    expect(dialogRef.close).toHaveBeenCalledWith(DialogResponse.EVENT_VIEWER_EDIT_ALL);
  });

  it('egy alkalom szerkesztése EVENT_VIEWER_EDIT_ONE választ ad', () => {
    component.onEditMassOne();

    expect(dialogRef.close).toHaveBeenCalledWith(DialogResponse.EVENT_VIEWER_EDIT_ONE);
  });

  /**
   * Ha a felhasználó egyszer már rábólintott a szerkesztésre, ne kérdezzük újra minden
   * misénél — a megosztott szolgáltatás ezt jegyzi meg.
   */
  it('korábbi megerősítés után nem kérdez újra', () => {
    editConfirmation.isConfirmed.and.returnValue(true);

    component.onSuggestClicked();

    expect(dialog.open).not.toHaveBeenCalled();
    expect(component.confirmedEdit).toBeTrue();
  });

  it('első alkalommal megerősítést kér, és a döntést megjegyzi', () => {
    valaszol(DialogResponse.CONTINUE);

    component.onSuggestClicked();

    expect(dialog.open).toHaveBeenCalled();
    expect(editConfirmation.confirm).toHaveBeenCalled();
    expect(component.confirmedEdit).toBeTrue();
  });

  it('elutasított megerősítésnél bezárja az ablakot', () => {
    valaszol('MEGSE');

    component.onSuggestClicked();

    expect(component.confirmedEdit).toBeFalse();
    expect(dialogRef.close).toHaveBeenCalled();
  });
});
