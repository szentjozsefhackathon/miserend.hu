import { Injectable } from '@angular/core';
import {HttpClient, HttpParams} from '@angular/common/http';
import {Observable} from 'rxjs';
import {Mass} from './model/mass';
import {ChangeRequest} from './model/http/change-request';
import {SuggestionPackage, SuggestionState} from './model/suggestion-package';
import {Church} from './model/church';
import {LiturgicalDay} from './model/liturgical-day';
import {environment} from '../environments/environment';
import {catchError} from 'rxjs/operators';
import {throwError} from 'rxjs';
import {MatSnackBarService} from './services/mat-snack-bar.service';

@Injectable({
  providedIn: 'root'
})
export class EventService {

  constructor(private http: HttpClient, private snackBarService: MatSnackBarService) {}

  /**
   * #506: `withFamily` esetén a plébánia és a fíliái is jönnek, a miséikkel együtt.
   *
   * Alapértelmezésben KIKAPCSOLVA, hogy az egy-templomos betöltés kérése és válasza
   * változatlan maradjon.
   */
  getChurch(churchId: number, withFamily: boolean = false): Observable<Church> {
    const url = environment.apiUrl + 'church/' + churchId + (withFamily ? '?family=1' : '');
    return this.http.get<Church>(url).pipe(
      catchError(error => {
        console.error('[EventService] Hiba a templom adatainak betöltésekor:', error);
        this.snackBarService.error('Nem sikerült a templom adatait betölteni.');
        return throwError(() => error);
      })
    );
  }

  saveChanges(churchId: number, masses: Mass[], deletedMasses: number[]): Observable<Mass[]> {
    const changeRequest: ChangeRequest = {
      masses: masses,
      deletedMasses: deletedMasses
    }
    return this.http.post<any[]>(environment.apiUrl+'masses/'+churchId, changeRequest).pipe(
      catchError(error => {
        console.error('[EventService] Hiba a változások mentésekor:', error);
        this.snackBarService.error('Nem sikerült a változásokat menteni.');
        return throwError(() => error);
      })
    );
  }

  sendToApprove(churchId: number, suggestionPackage: SuggestionPackage) {
    return this.http.post<any[]>(environment.apiUrl+'suggestions/church/'+churchId, suggestionPackage).pipe(
      catchError(error => {
        console.error('[EventService] Hiba a javaslatok küldésekor:', error);
        this.snackBarService.error('Nem sikerült a javaslatokat elküldeni.');
        return throwError(() => error);
      })
    );
  }

  getSuggestions(churchId: number, state?: SuggestionState): Observable<SuggestionPackage[]> {
    let url = environment.apiUrl+'suggestions/church/'+churchId;
    if (state) {
      url += '/' + state;
    }
    return this.http.get<any[]>(url).pipe(
      catchError(error => {
        console.error('[EventService] Hiba a javaslatok betöltésekor:', error);
        this.snackBarService.error('Nem sikerült a javaslatokat betölteni.');
        return throwError(() => error);
      })
    );
  }

  simpleAcceptSuggestionPackage(suggestionPackage: SuggestionPackage): Observable<{
    suggestionPackages: SuggestionPackage[];
    calendarMasses: Mass[]
  }> {
    const suffix = `suggestions/accept/${suggestionPackage.id}`;
    const body = {state: SuggestionState.ACCEPTED};
    return this.http.post<{ suggestionPackages: SuggestionPackage[]; calendarMasses: Mass[] }>(
      environment.apiUrl + suffix,
      body
    ).pipe(
      catchError(error => {
        console.error('[EventService] Hiba a javaslat elfogadásakor:', error);
        this.snackBarService.error('Nem sikerült a javaslatot elfogadni.');
        return throwError(() => error);
      })
    );
  }

  simpleRejectSuggestionPackage(suggestionPackage: SuggestionPackage, notifySender: boolean = false): Observable<{
    suggestionPackages: SuggestionPackage[];
    calendarMasses: Mass[]
  }> {
    const suffix = `suggestions/reject/${suggestionPackage.id}`;
    // #543: notify_sender → a backend csak akkor küld elutasító emailt a beküldőnek,
    // ha a kezelő bepipálta. Default false (néha látszik, hogy valaki véletlen többet küldött be).
    const body = {state: SuggestionState.REJECTED, notify_sender: notifySender};
    return this.http.post<{ suggestionPackages: SuggestionPackage[]; calendarMasses: Mass[] }>(
      environment.apiUrl + suffix,
      body
    ).pipe(
      catchError(error => {
        console.error('[EventService] Hiba a javaslat elutasításakor:', error);
        this.snackBarService.error('Nem sikerült a javaslatot elutasítani.');
        return throwError(() => error);
      })
    );
  }

  getLiturgicalDays(from: string, until: string): Observable<{[date: string]: LiturgicalDay}> {
    const params = new HttpParams()
      .set('from', from)
      .set('until', until);
    
    const ajaxUrl = `${environment.apiUrl}liturgicaldays`;
    
    return this.http.get<{[date: string]: LiturgicalDay}>(
      ajaxUrl,
      { params }
    ).pipe(
      catchError(error => {
        console.error('[EventService] Hiba a liturgikus napok betöltésekor:', error);
        // Don't show error to user for liturgical days - it's a nice-to-have feature
        return throwError(() => error);
      })
    );
  }

}
