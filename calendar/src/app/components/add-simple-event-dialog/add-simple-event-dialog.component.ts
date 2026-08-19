import {Component, inject} from '@angular/core';
import {MatIconModule} from '@angular/material/icon';
import {MatCardModule} from '@angular/material/card';
import {MatButtonModule} from '@angular/material/button';
import {SimpleDialogData} from '../church-calendar/church-calendar.component';
import {MAT_DIALOG_DATA, MatDialogClose, MatDialogRef} from '@angular/material/dialog';
import {DateTimeUtil} from '../../util/date-time-util';
import {DialogResponse} from '../../enum/dialog-response';
import {TranslatePipe} from '@ngx-translate/core';
import {MatFormFieldModule} from '@angular/material/form-field';
import {MatSelectModule} from '@angular/material/select';
import {FormsModule} from '@angular/forms';
import {MassUtil} from '../../util/mass-util';
import {ChurchFamilyMember} from '../../model/church';

@Component({
  selector: 'app-add-simple-event-dialog',
  imports: [
    MatIconModule,
    MatCardModule,
    MatButtonModule,
    TranslatePipe,
    MatDialogClose,
    MatFormFieldModule,
    MatSelectModule,
    FormsModule
  ],
  templateUrl: './add-simple-event-dialog.component.html',
  styleUrls: ['../../../styles.scss', './add-simple-event-dialog.component.css']
})
export class AddSimpleEventDialogComponent {

  readonly dialogRef = inject(MatDialogRef<AddSimpleEventDialogComponent>);
  readonly data = inject<SimpleDialogData>(MAT_DIALOG_DATA);
  readonly dateTime: string;
  readonly title: string;

  /**
   * #506: melyik templomhoz kerüljön az esemény.
   *
   * Csak akkor jelenik meg, ha tényleg van miből választani (a plébániának vannak
   * fíliái, és többhöz is van írásjogunk). Egy templomnál a választó felesleges zaj.
   *
   * A választást a `data` objektumba írjuk vissza, mert a dialógus szerződése egy
   * enum-válasz — azt nem akartam megváltoztatni a meglévő hívók miatt.
   */
  churchId?: number;

  constructor() {
    this.dateTime = DateTimeUtil.getDateTimeString(this.data.dateTime);
    this.title = this.data.title;
    this.churchId = this.data.selectedChurchId;
  }

  get churches() {
    return this.data.churches ?? [];
  }

  get hasChurchChoice(): boolean {
    return this.churches.length > 1;
  }

  onChurchChange(churchId: number): void {
    this.churchId = churchId;
    this.data.selectedChurchId = churchId;
  }

  /**
   * #506: mindig „település, templomnév" — a naptár rövidítő szabálya itt NEM jó.
   * A választó döntési pont: ha félreértjük, a mise rossz templomhoz íródik.
   */
  churchLabel(tag: ChurchFamilyMember): string {
    return MassUtil.familySelectorLabel(tag);
  }

  onSaveSimple(): void {
    this.dialogRef.close(DialogResponse.SAVE_SIMPLE);
  }
  onMoreDetails(): void {
    this.dialogRef.close(DialogResponse.MORE_DETAILS);
  }

}
