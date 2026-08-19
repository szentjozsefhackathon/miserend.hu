import { Routes } from '@angular/router';
import {SuggestionsComponent} from './components/suggestions/suggestions.component';
import {ChurchComponent} from './components/church/church.component';
import {EditScheduleComponent} from './components/edit-schedule/edit-schedule.component';
import {PeriodYearEditorComponent} from './components/period-year-editor/period-year-editor.component';
import {SearchComponent} from './components/search/search.component';
import { WidgetComponent } from './components/widget/widget.component';

export const routes: Routes = [
  // Specific sub-routes must come before the generic 'templom/:id' route
  { path: 'templom/:id/widget', component: WidgetComponent },
  { path: 'templom/:id/editschedule', component: EditScheduleComponent },
  { path: 'templom/:id/javaslatok', component: SuggestionsComponent },
  { path: 'templom/:id', component: ChurchComponent },
  /*
   * #830: a plébánia-család közös miserendje saját útvonalon.
   *
   * Ugyanaz a komponens, mint a `templom/:id` — a család módot a ChurchComponent az
   * ÚTVONALBÓL ismeri fel, nem query-paraméterből. Enélkül a `/plebania/:id` csak
   * átirányítás lehetne a `?csalad=1`-re, és a szép URL rögtön el is tűnne a
   * címsorból, amint betölt a lap.
   *
   * A `?csalad=1` továbbra is működik: a régi linkek nem törnek el.
   */
  { path: 'plebania/:id', component: ChurchComponent },
  { path: 'periodyeareditor', component: PeriodYearEditorComponent },
  { path: '', component: SearchComponent },
];
