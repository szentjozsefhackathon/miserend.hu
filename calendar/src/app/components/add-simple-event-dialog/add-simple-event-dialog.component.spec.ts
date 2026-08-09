import {ComponentFixture, TestBed} from '@angular/core/testing';
import {MAT_DIALOG_DATA, MatDialogRef} from '@angular/material/dialog';
import {provideTranslateService} from '@ngx-translate/core';

import {AddSimpleEventDialogComponent} from './add-simple-event-dialog.component';
import {DialogResponse} from '../../enum/dialog-response';

describe('AddSimpleEventDialogComponent', () => {
  let component: AddSimpleEventDialogComponent;
  let fixture: ComponentFixture<AddSimpleEventDialogComponent>;
  let dialogRefMock: { close: jasmine.Spy };

  async function setup(dialogData = {dateTime: new Date('2026-03-15T10:00:00'), title: 'SIMPLE_EVENT.TITLE'}) {
    dialogRefMock = {close: jasmine.createSpy('close')};

    await TestBed.configureTestingModule({
      imports: [AddSimpleEventDialogComponent],
      providers: [
        provideTranslateService(),
        {provide: MAT_DIALOG_DATA, useValue: dialogData},
        {provide: MatDialogRef, useValue: dialogRefMock},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(AddSimpleEventDialogComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  }

  it('exposes the dialog data as title + formatted dateTime string', async () => {
    await setup({dateTime: new Date('2026-03-15T10:00:00'), title: 'Új mise'});

    expect(component.title).toBe('Új mise');
    expect(component.dateTime).toBeTruthy();
    expect(typeof component.dateTime).toBe('string');
  });

  it('onSaveSimple() closes the dialog with DialogResponse.SAVE_SIMPLE', async () => {
    await setup();
    component.onSaveSimple();
    expect(dialogRefMock.close).toHaveBeenCalledWith(DialogResponse.SAVE_SIMPLE);
  });

  it('onMoreDetails() closes the dialog with DialogResponse.MORE_DETAILS', async () => {
    await setup();
    component.onMoreDetails();
    expect(dialogRefMock.close).toHaveBeenCalledWith(DialogResponse.MORE_DETAILS);
  });

  it('the two save / details handlers fire exactly once and with distinct payloads', async () => {
    await setup();
    component.onSaveSimple();
    component.onMoreDetails();
    expect(dialogRefMock.close).toHaveBeenCalledTimes(2);
    expect(dialogRefMock.close.calls.argsFor(0)).toEqual([DialogResponse.SAVE_SIMPLE]);
    expect(dialogRefMock.close.calls.argsFor(1)).toEqual([DialogResponse.MORE_DETAILS]);
  });
});
