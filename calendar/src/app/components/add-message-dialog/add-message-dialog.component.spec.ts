import {ComponentFixture, TestBed} from '@angular/core/testing';
import {MAT_DIALOG_DATA, MatDialogRef} from '@angular/material/dialog';
import {provideTranslateService} from '@ngx-translate/core';

import {AddMessageDialogComponent} from './add-message-dialog.component';
import {DialogResponse} from '../../enum/dialog-response';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * Ez a dialógus két, egymástól nagyon eltérő szerepet lát el, és a különbséget egyetlen
 * `decision` jelző dönti el:
 *
 *   - `decision: false` — tájékoztatás („Javaslatod sikeresen beküldve").
 *   - `decision: true`  — DÖNTÉST kér („Nincs húsvéti miserend. Mentsük így?").
 *
 * A második esetben a válasz `CONTINUE`, és a hívó ERRE indítja el a mentést. Ha a
 * dialógus más értékkel zárna, a mentés némán elmaradna — a felhasználó azt hinné,
 * elmentette a miserendet.
 */
describe('AddMessageDialogComponent', () => {

  let fixture: ComponentFixture<AddMessageDialogComponent>;
  let component: AddMessageDialogComponent;
  let dialogRef: jasmine.SpyObj<MatDialogRef<AddMessageDialogComponent>>;

  const uzenet = {message: 'Mentsük így?', decision: true};

  beforeEach(async () => {
    dialogRef = jasmine.createSpyObj('MatDialogRef', ['close']);

    await TestBed.configureTestingModule({
      imports: [AddMessageDialogComponent],
      providers: [
        provideTranslateService(),
        {provide: MatDialogRef, useValue: dialogRef},
        {provide: MAT_DIALOG_DATA, useValue: uzenet},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(AddMessageDialogComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('a kapott üzenetet megjeleníti', () => {
    expect(fixture.nativeElement.textContent).toContain('Mentsük így?');
  });

  /**
   * Ez a szerződés lényege: a hívó a CONTINUE értékre menti a miserendet.
   */
  it('a folytatás CONTINUE értékkel zárja a dialógust', () => {
    component.onContinue();

    expect(dialogRef.close).toHaveBeenCalledWith(DialogResponse.CONTINUE);
  });

  it('a döntés-jelzőt a hívótól veszi át', () => {
    expect(component.data.decision).toBeTrue();
  });
});
