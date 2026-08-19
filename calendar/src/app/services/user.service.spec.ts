import {TestBed} from '@angular/core/testing';
import {provideHttpClient} from '@angular/common/http';
import {HttpTestingController, provideHttpClientTesting} from '@angular/common/http/testing';

import {UserService} from './user.service';
import {environment} from '../../environments/environment';
import {User} from '../model/user';

/**
 * A naptár innen tudja meg, ki nézi — és ami fontosabb, mit szabad neki.
 *
 * A szolgáltatás egyetlen érdemi döntése az, hogy HIBÁNÁL NEM DOB, hanem üres
 * alapfelhasználót ad vissza. Ez szándékos: a naptárnak be kell tudnia töltődni akkor
 * is, ha a látogató nincs bejelentkezve (a `caluser` ilyenkor 401-et ad). Viszont épp
 * ezért kell rögzíteni, hogy az alapfelhasználó tényleg ÜRES — ha valaha kapna
 * kedvenceket vagy nevet, a nem bejelentkezett látogató idegen adatot látna.
 */
describe('UserService', () => {

  let service: UserService;
  let httpMock: HttpTestingController;

  const url = `${environment.apiUrl}caluser`;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        UserService,
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(UserService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('a caluser végpontról tölt', () => {
    service.loadUser().subscribe();

    const keres = httpMock.expectOne(url);
    expect(keres.request.method).toBe('GET');
    keres.flush(null);
  });

  it('a megkapott felhasználót adja tovább', () => {
    const felhasznalo: User = {
      username: 'gondnok',
      nickname: 'Gondnok',
      name: 'Teszt Gondnok',
      email: 'gondnok@example.com',
      favorites: [12, 34],
    } as User;

    let eredmeny: any;
    service.loadUser().subscribe(u => eredmeny = u);
    httpMock.expectOne(url).flush(felhasznalo);

    expect(eredmeny).toEqual(felhasznalo);
  });

  /** Nem bejelentkezett látogató: a szerver 401-et ad, a naptárnak mégis mennie kell. */
  it('hibánál nem dob, hanem üres alapfelhasználót ad', () => {
    let eredmeny: any;
    let hiba: any = null;

    service.loadUser().subscribe({
      next: u => eredmeny = u,
      error: e => hiba = e,
    });
    httpMock.expectOne(url).flush('Unauthorized', {status: 401, statusText: 'Unauthorized'});

    expect(hiba)
      .withContext('a hiba nem juthat el a hívóig, különben a naptár be sem töltődik')
      .toBeNull();
    expect(eredmeny.username).toBe('');
    expect(eredmeny.favorites).toEqual([]);
  });

  it('hálózati hibánál is az alapfelhasználó jön', () => {
    let eredmeny: any;
    service.loadUser().subscribe(u => eredmeny = u);
    httpMock.expectOne(url).error(new ProgressEvent('network error'));

    expect(eredmeny.username).toBe('');
  });

  /** Üres válasznál (a szerver `null`-t ad) ugyanaz az alapfelhasználó kell. */
  it('üres válasznál is az alapfelhasználó jön', () => {
    let eredmeny: any;
    service.loadUser().subscribe(u => eredmeny = u);
    httpMock.expectOne(url).flush(null);

    expect(eredmeny.username).toBe('');
    expect(eredmeny.email).toBe('');
  });

  /**
   * Ha az alapfelhasználó bármikor adatot kapna, a nem bejelentkezett látogató
   * idegen kedvenceket látna a naptárában.
   */
  it('az alapfelhasználó minden mezője üres', () => {
    expect(service.defaultUser.username).toBe('');
    expect(service.defaultUser.nickname).toBe('');
    expect(service.defaultUser.name).toBe('');
    expect(service.defaultUser.email).toBe('');
    expect(service.defaultUser.favorites).toEqual([]);
  });
});
