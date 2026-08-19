import {ComponentFixture, TestBed} from '@angular/core/testing';
import {provideRouter} from '@angular/router';
import {TranslateService, provideTranslateService} from '@ngx-translate/core';

import {AppComponent} from './app.component';
import {SpinnerService} from './services/spinner.service';

/**
 * #436: CLI-generált csonk volt (`should create`), `xdescribe`-bal kikapcsolva.
 *
 * A gyökérkomponensnek egyetlen saját dolga van, és az nem mindegy: MAGYARRA állítja a
 * felületet. A naptárat plébániai gondnokok és idős atyák használják — ha a nyelv nem
 * áll be, angol kulcsokat vagy nyers azonosítókat látnak.
 */
describe('AppComponent', () => {

  let fixture: ComponentFixture<AppComponent>;
  let translate: TranslateService;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AppComponent],
      providers: [
        provideRouter([]),
        provideTranslateService(),
        SpinnerService,
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(AppComponent);
    translate = TestBed.inject(TranslateService);
  });

  it('magyarra állítja a felületet', () => {
    // Az ngx-translate ebben a verzióban szignálként adja a nyelvet.
    const nyelv = typeof translate.currentLang === 'function'
      ? (translate.currentLang as () => string)()
      : translate.currentLang;

    expect(nyelv).toBe('hu');
  });

  it('a töltésjelző szolgáltatás elérhető a felületnek', () => {
    expect(fixture.componentInstance.spinnerService).toBeInstanceOf(SpinnerService);
  });
});
