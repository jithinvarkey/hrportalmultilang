import { Injectable, Injector, computed, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { TranslateService } from '@ngx-translate/core';
import { AppLang, DEFAULT_LANG, LANG_LOCALE, SUPPORTED_LANGS } from '../i18n/i18n.config';

const STORAGE_KEY = 'hrms_lang';

@Injectable({ providedIn: 'root' })
export class LanguageService {
  readonly current = signal<AppLang>(DEFAULT_LANG);
  readonly isRtl   = computed(() => this.current() === 'ar');
  readonly dir     = computed<'rtl' | 'ltr'>(() => (this.isRtl() ? 'rtl' : 'ltr'));
  readonly locale  = computed(() => LANG_LOCALE[this.current()]);

  constructor(private translate: TranslateService, private injector: Injector) {}

  // Resolved lazily: AuthService injects this service, and HttpClient's interceptors inject AuthService,
  // so asking for HttpClient in the constructor creates a circular dependency (NG0200).
  private get http(): HttpClient {
    return this.injector.get(HttpClient);
  }

  /** Called once at app start (APP_INITIALIZER). Default language is English. */
  init(): Promise<unknown> {
    this.translate.addLangs(SUPPORTED_LANGS);
    this.translate.setDefaultLang(DEFAULT_LANG);
    return this.apply(this.stored() ?? DEFAULT_LANG, false);
  }

  /**
   * User picked a screen language: remember it in this browser and on their account (for other devices).
   * This does NOT change the email language — that is the employee's communication language on the profile page.
   */
  use(lang: string, persistToServer = true): Promise<unknown> {
    const next = this.normalize(lang);
    try { localStorage.setItem(STORAGE_KEY, next); } catch { /* storage unavailable */ }
    return this.apply(next, persistToServer);
  }

  toggle(): void {
    this.use(this.current() === 'ar' ? 'en' : 'ar');
  }

  /**
   * Reconcile with the language saved on the user account.
   * At login, a choice made in this browser (e.g. on the login screen) wins and is saved to the account;
   * otherwise the account preference is adopted, so a new device opens in the user's language.
   */
  syncFromUser(user: any, atLogin = false): void {
    const pref = user?.preferred_language;
    const local = this.stored();
    if (atLogin && local) {
      if (pref !== local) this.http.put('/api/v1/auth/language', { language: local }).subscribe({ error: () => {} });
      return;
    }
    if (pref && SUPPORTED_LANGS.includes(pref) && pref !== this.current()) {
      try { localStorage.setItem(STORAGE_KEY, pref); } catch { /* storage unavailable */ }
      this.apply(pref, false);
    }
  }

  /** Synchronous lookup for TS code (toasts, confirm dialogs, labels built in components). */
  t(key: string, params?: Record<string, any>): string {
    return this.translate.instant(key, params);
  }

  private apply(lang: AppLang, persistToServer: boolean): Promise<unknown> {
    this.current.set(lang);

    const html = document.documentElement;
    html.setAttribute('lang', lang);
    html.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
    html.classList.toggle('lang-ar', lang === 'ar');
    html.classList.toggle('lang-en', lang === 'en');

    if (persistToServer && this.hasToken()) {
      this.http.put('/api/v1/auth/language', { language: lang }).subscribe({ error: () => {} });
      this.updateCachedUser(lang);
    }
    return new Promise(resolve => this.translate.use(lang).subscribe({ next: resolve, error: resolve }));
  }

  /** The language explicitly chosen in this browser, or null if the user never picked one. */
  private stored(): AppLang | null {
    try {
      const v = localStorage.getItem(STORAGE_KEY);
      return SUPPORTED_LANGS.includes(v as AppLang) ? (v as AppLang) : null;
    } catch { return null; }
  }

  private normalize(lang: string | null | undefined): AppLang {
    return SUPPORTED_LANGS.includes(lang as AppLang) ? (lang as AppLang) : DEFAULT_LANG;
  }

  private hasToken(): boolean {
    return !!(sessionStorage.getItem('hrms_token') || localStorage.getItem('hrms_token'));
  }

  private updateCachedUser(lang: AppLang): void {
    for (const store of [sessionStorage, localStorage]) {
      const raw = store.getItem('hrms_user');
      if (!raw) continue;
      try { store.setItem('hrms_user', JSON.stringify({ ...JSON.parse(raw), preferred_language: lang })); } catch { /* ignore */ }
    }
  }
}
