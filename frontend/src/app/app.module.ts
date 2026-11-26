import { APP_INITIALIZER, NgModule, isDevMode } from '@angular/core';
import { registerLocaleData } from '@angular/common';
import localeArSa from '@angular/common/locales/ar-SA';
import { BrowserModule } from '@angular/platform-browser';
import { BrowserAnimationsModule } from '@angular/platform-browser/animations';
import { HttpBackend, HttpClientModule } from '@angular/common/http';
import { HTTP_INTERCEPTORS } from '@angular/common/http';
import { BidiModule } from '@angular/cdk/bidi';
import { StoreModule } from '@ngrx/store';
import { EffectsModule } from '@ngrx/effects';
import { provideStoreDevtools } from '@ngrx/store-devtools';
import { ToastrModule } from 'ngx-toastr';
import { TranslateLoader, TranslateModule } from '@ngx-translate/core';

import { AppRoutingModule } from './app-routing.module';
import { AppComponent } from './app.component';
import { CoreModule } from './core/core.module';
import { SharedModule } from './shared/shared.module';
import { LanguageInterceptor } from './core/interceptors/language.interceptor';
import { LanguageService } from './core/services/language.service';
import { multiFileLoaderFactory } from './core/i18n/multi-file-translate.loader';
import { DEFAULT_LANG } from './core/i18n/i18n.config';

registerLocaleData(localeArSa, 'ar-SA');

export function initLanguage(lang: LanguageService) {
  return () => lang.init();
}

@NgModule({
  declarations: [AppComponent],
  imports: [
    BrowserModule,
    BrowserAnimationsModule,
    HttpClientModule,
    BidiModule,
    AppRoutingModule,
    CoreModule,
    SharedModule,
    TranslateModule.forRoot({
      defaultLanguage: DEFAULT_LANG,
      loader: { provide: TranslateLoader, useFactory: multiFileLoaderFactory, deps: [HttpBackend] },
    }),
    StoreModule.forRoot({}),
    EffectsModule.forRoot([]),
    ToastrModule.forRoot({ positionClass: 'toast-top-right', timeOut: 3000 })
  ],
  providers: [
    // AuthInterceptor and HttpActivityInterceptor are provided by CoreModule.
    { provide: HTTP_INTERCEPTORS, useClass: LanguageInterceptor, multi: true },
    { provide: APP_INITIALIZER, useFactory: initLanguage, deps: [LanguageService], multi: true },
    // NgRx devtools in development only: in production they would expose the signed-in user and
    // token state to the browser extension. Providers (unlike imports) may be conditional.
    // (angular.json has no environment file replacement, so isDevMode() is the reliable switch.)
    ...(isDevMode() ? [provideStoreDevtools({ maxAge: 25 })] : []),
  ],
  bootstrap: [AppComponent]
})
export class AppModule {}
