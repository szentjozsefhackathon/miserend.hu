import {ComponentFixture, TestBed} from '@angular/core/testing';
import {provideHttpClient} from '@angular/common/http';
import {HttpTestingController, provideHttpClientTesting} from '@angular/common/http/testing';
import {provideTranslateService} from '@ngx-translate/core';

import {SearchComponent} from './search.component';
import {MatSnackBarService} from '../../services/mat-snack-bar.service';
import {environment} from '../../../environments/environment';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * A kereső űrlapja a szerverről kapott listákból épül fel (egyházmegyék, megyék,
 * városok, nyelvek, rítusok, zenék, korosztályok). Amit érdemes rögzíteni:
 *
 *  - a listák a válaszból TÉNYLEG feltöltődnek — üres legördülőkkel a kereső
 *    használhatatlan, és ez a hiba csendes;
 *  - a csoportonkénti szűrés (`liturgy` / `music` / `age`) a `group` mező alapján megy,
 *    kis-nagybetűtől és szóközöktől függetlenül — a szerver adata ebben nem egységes;
 *  - a zenék és a korosztályok kapnak egy „meghatározatlan" tételt, hogy a hiányzó
 *    adatra is lehessen szűrni.
 */
describe('SearchComponent', () => {

  let fixture: ComponentFixture<SearchComponent>;
  let component: SearchComponent;
  let httpMock: HttpTestingController;

  const valasz = {
    egyhazmegyek: {1: {id: 1, name: 'Esztergom-Budapest'}},
    espereskeruletek: {1: {id: 1, name: 'Budai'}},
    orszagok: {12: {id: 12, name: 'Magyarország'}},
    megyek: {1: {id: 1, name: 'Pest'}},
    varosok: {1: {id: 1, name: 'Szentendre'}},
    languages: {hu: {id: 'hu', name: 'magyar'}},
    attributes: {
      1: {id: 1, name: 'római', group: 'liturgy'},
      2: {id: 2, name: 'orgona', group: ' Music '},
      3: {id: 3, name: 'ifjúsági', group: 'AGE'},
      4: {id: 4, name: 'egyéb', group: 'other'},
    },
  };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [SearchComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideTranslateService(),
        {provide: MatSnackBarService, useValue: jasmine.createSpyObj('MatSnackBarService', ['error', 'success'])},
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(SearchComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  });

  const betolt = () => {
    fixture.detectChanges();
    httpMock.expectOne(environment.apiUrl + 'search').flush(valasz);
  };

  it('a kereső adatait a szerverről kéri', () => {
    fixture.detectChanges();

    const req = httpMock.expectOne(environment.apiUrl + 'search');
    expect(req.request.method).toBe('GET');
    req.flush(valasz);
  });

  it('feltölti a helyi listákat', () => {
    betolt();

    expect(component.egyhazmegyek.length).toBe(1);
    expect(component.megyek.length).toBe(1);
    expect(component.varosok.length).toBe(1);
    expect(component.nyelvek.length).toBe(1);
  });

  /**
   * A `group` mező a szerver adatában nem egységes: van benne vezető szóköz és
   * nagybetű is. A szűrésnek ezt el kell viselnie, különben a rítus- vagy
   * zene-választó némán üres marad.
   */
  it('a csoportokat kis-nagybetűtől és szóköztől függetlenül szűri', () => {
    betolt();

    expect(component.ritusok.map(r => r.name)).toContain('római');
    expect(component.zenek.map(z => z.name)).toContain('orgona');
    expect(component.korosztalyok.map(k => k.name)).toContain('ifjúsági');
  });

  it('az ismeretlen csoportú tétel egyik listába sem kerül be', () => {
    betolt();

    const mind = [...component.ritusok, ...component.zenek, ...component.korosztalyok].map(x => x.name);
    expect(mind).not.toContain('egyéb');
  });

  /** A hiányzó adatra is lehessen szűrni — ezért kap külön tételt. */
  it('a zenéknél és a korosztályoknál van „meghatározatlan" tétel', () => {
    betolt();

    expect(component.zenek.some(z => z.id === -1)).toBeTrue();
    expect(component.korosztalyok.some(k => k.id === -1)).toBeTrue();
  });

  it('felépíti a kereső űrlapot', () => {
    betolt();

    expect(component.searchForm.contains('kulcsszo')).toBeTrue();
    expect(component.searchForm.contains('telepules')).toBeTrue();
  });
});
