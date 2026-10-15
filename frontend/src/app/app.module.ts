import { APP_INITIALIZER, NgModule } from '@angular/core';
import { registerLocaleData } from '@angular/common';
import localeArSa from '@angular/common/locales/ar-SA';
import { BrowserModule } from '@angular/platform-browser';
import { BrowserAnimationsModule } from '@angular/platform-browser/animations';
import { HttpBackend, HttpClientModule } from '@angular/common/http';
import { HTTP_INTERCEPTORS } from '@angular/common/http';
import { BidiModule } from '@angular/cdk/bidi';
import { StoreModule } from '@ngrx/store';
import { EffectsModule } from '@ngrx/effects';
import { StoreDevtoolsModule } from '@ngrx/store-devtools';
import { ToastrModule } from 'ngx-toastr';
import { TranslateLoader, TranslateModule } from '@ngx-translate/core';

import { AppRoutingModule } from './app-routing.module';
import { AppComponent } from './app.component';
import { CoreModule } from './core/core.module';
import { SharedModule } from './shared/shared.module';
import { AuthInterceptor } from './core/interceptors/auth.interceptor';
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
    StoreDevtoolsModule.instrument({ maxAge: 25 }),
    ToastrModule.forRoot({ positionClass: 'toast-top-right', timeOut: 3000 })
  ],
  providers: [
    { provide: HTTP_INTERCEPTORS, useClass: AuthInterceptor, multi: true },
    { provide: HTTP_INTERCEPTORS, useClass: LanguageInterceptor, multi: true },
    { provide: APP_INITIALIZER, useFactory: initLanguage, deps: [LanguageService], multi: true },
  ],
  bootstrap: [AppComponent]
})
export class AppModule {}
