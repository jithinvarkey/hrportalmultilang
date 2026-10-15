/**
 * Translation files live in  src/assets/i18n/<lang>/<namespace>.json
 *
 * Each namespace file owns ONE top-level key (e.g. leave.json -> { "LEAVE": {...} })
 * so modules can be translated independently without editing a shared file.
 * Shared keys (COMMON, STATUS, NAV, ROLES, LAYOUT) live in common.json.
 */
export type AppLang = 'en' | 'ar';

export const DEFAULT_LANG: AppLang = 'en';
export const SUPPORTED_LANGS: AppLang[] = ['en', 'ar'];

export const I18N_NAMESPACES = [
  'common',
  'auth',
  'dashboard',
  'employees',
  'orgchart',
  'attendance',
  'leave',
  'contracts',
  'separations',
  'recruitment',
  'performance',
  'payroll',
  'loans',
  'requests',
  'announcements',
  'policies',
  'admin',
  'assets',
  'profile',
  'reports',
] as const;

/** Locale ids used for Angular date/number/currency formatting. */
export const LANG_LOCALE: Record<AppLang, string> = {
  en: 'en-US',
  ar: 'ar-SA',
};
