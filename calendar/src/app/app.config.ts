import {ApplicationConfig, provideZoneChangeDetection} from '@angular/core';
import { provideRouter } from '@angular/router';

import { routes } from './app.routes';
import {provideHttpClient, HTTP_INTERCEPTORS} from '@angular/common/http';
import {HttpTimeoutInterceptor} from './http.interceptor';
import {HttpErrorInterceptor} from './http-error.interceptor';
import {OverlayContainer} from '@angular/cdk/overlay';
import {InlineOverlayContainer} from './inline-overlay-container';
import { LOCALE_ID } from '@angular/core';
import { MAT_DATE_LOCALE } from '@angular/material/core';
import { registerLocaleData } from '@angular/common';
import localeHu from '@angular/common/locales/hu';
import {provideTranslateService} from '@ngx-translate/core';
import {provideTranslateHttpLoader} from '@ngx-translate/http-loader';

registerLocaleData(localeHu);

export const appConfig: ApplicationConfig = {
  providers: [
    provideZoneChangeDetection({ eventCoalescing: true }),
    provideRouter(routes),
    provideHttpClient(),
    {
      provide: HTTP_INTERCEPTORS,
      useClass: HttpTimeoutInterceptor,
      multi: true
    },
    {
      provide: HTTP_INTERCEPTORS,
      useClass: HttpErrorInterceptor,
      multi: true
    },
    {
      provide: OverlayContainer,
      useClass: InlineOverlayContainer
    },
    { provide: LOCALE_ID, useValue: 'hu' },
    { provide: MAT_DATE_LOCALE, useValue: 'hu' },
    provideTranslateService({
      lang: 'hu',
      loader: provideTranslateHttpLoader({
        prefix: '/i18n/',
        suffix: '.json',
      }),
    }),
  ],
};
