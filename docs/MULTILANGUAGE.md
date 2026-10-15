# Multi-language (English / Arabic) guide

The HR portal supports **English (default)** and **Arabic (RTL)**. There are two separate language settings:

| Setting | Stored in | Default | Changed by | Controls |
|---|---|---|---|---|
| **Screen language** | browser + `users.preferred_language` | English | EN/عربي switcher (login page, sidebar, mobile top bar) | What the user sees in the portal |
| **Communication language** | `employees.preferred_communication_language` | English | Employee on **My Profile**, or HR on the employee form | Language of every email / notification sent to the employee |

They are independent on purpose: someone can work in the English portal and still receive Arabic emails.

## How status values are translated — language files, not a database table

Statuses are stored in the database as **stable English codes** (`pending`, `manager_approved`, `on_leave` …).
They are never stored translated. Their display text lives in the language files:

| Where                | File                                              | Use                                       |
|----------------------|---------------------------------------------------|-------------------------------------------|
| Angular UI           | `frontend/src/assets/i18n/<lang>/common.json` → `STATUS` | `{{ row.status \| statusLabel }}`         |
| Emails / PDFs (PHP)  | `backend/lang/<lang>/status.php` (generated)      | `StatusLabel::for($code)` / `__('status.'.$code)` |

`backend/lang/*/status.php` is generated from the JSON: edit `common.json`, then run
`python tools/i18n/sync_status_labels.py`.

Why files and not a table: the status set is fixed by the code (enum columns and `if ($status === 'approved')`
logic), so a new status always ships with a code change anyway; files are versioned with that change, need no
joins or extra queries, work offline in the SPA, and keep code/label in one review.

**Admin-managed master data is different** — HR creates leave types, departments etc. at runtime, so those
get an Arabic column in the database: `departments.name_ar`, `designations.title_ar`, `leave_types.name_ar`,
`loan_types.name_ar`, `request_types.name_ar`, `units.name_ar` (empty = fall back to English).
Employees already have `arabic_name`.

## Frontend (Angular)

* Library: `@ngx-translate/core`. Files: `src/assets/i18n/<en|ar>/<namespace>.json`.
  Each namespace file owns **one top-level key** (`leave.json` → `{ "LEAVE": { … } }`). The namespace list is in
  `src/app/core/i18n/i18n.config.ts`. Shared keys: `COMMON`, `MESSAGES`, `VALIDATION`, `STATUS`, `ROLES`, `NAV`,
  `LAYOUT` (in `common.json`).
* Keys: `UPPER_SNAKE` groups, e.g. `LEAVE.FORM.START_DATE`. Reuse `COMMON.*` for generic words
  (Save, Cancel, Status, Actions …) instead of duplicating them.
* `SharedModule` exports everything needed on every page:
  * `{{ 'LEAVE.TITLE' | translate }}`, `[placeholder]="'COMMON.SEARCH' | translate"`,
    `{{ 'COMMON.SHOWING' | translate:{from:1,to:10,total:52} }}`
  * `{{ code | statusLabel }}` — status codes (optionally `statusLabel:'PERFORMANCE'` → `STATUS_CONTEXT.PERFORMANCE.*`)
  * `{{ leaveType | locName }}`, `{{ designation | locName:'title' }}` — Arabic column when Arabic is active
  * `{{ date | locDate:'d MMM y' }}` — like `date`, with Arabic month names. Numbers keep the `number` pipe
    (Western digits, `1,234.50`, as is normal in Saudi business software).
* In TypeScript (toasts, confirm(), labels built in code): inject `LanguageService` and call
  `this.lang.t('LEAVE.MSG.SUBMITTED')`. For lists rebuilt on language change, compute labels in getters or
  in the template, not once in `ngOnInit`.
* Arabic font: **IBM Plex Sans Arabic** (the typeface of the Saudi DGA digital-government design system),
  Tajawal fallback — applied globally when `<html lang="ar">`.
* RTL: `<html dir="rtl">` is set by `LanguageService`, and the app root has `[dir]` for Angular Material.
  In SCSS use **logical properties**: `margin-inline-start`, `padding-inline-end`, `inset-inline-start`,
  `border-inline-end`, `text-align: start/end` — not `left/right`. Add `class="flip-rtl"` to directional icons
  (`arrow_back`, `chevron_right`, `logout` …). Use `dir="ltr"` / `class="ltr"` for emails, phone numbers, IBANs,
  codes, and numeric/date inputs that must stay left-to-right.

## Responsive

Breakpoints: tablet `≤1024px`, mobile `≤768px`, small `≤480px` (sidebar becomes a drawer at `≤900px`).
`styles/styles.scss` has a global safety net for shared patterns (`.page-hdr`, `.modal-panel`, `.detail-drawer`,
`.stat-grid`, `.form-grid`, tables scroll horizontally inside `.data-card`/`.table-card`). Component SCSS adds
module-specific rules. Wrap wide tables in `<div class="table-scroll">`. No horizontal page scroll at 360px.

## Backend (Laravel)

* `SetLocale` middleware (api group) applies `Accept-Language` from the SPA → validation errors and API messages
  come back in the user's language. Messages use `__('English text')` with translations in `lang/ar.json`.
* `PUT /api/v1/auth/language {language}` saves the screen language; login and `/auth/me` return
  `preferred_language` and `employee.preferred_communication_language`.
* `PUT /api/v1/profile/communication-language {language: "en"|"ar"}` saves the employee's communication
  language (`GET /api/v1/profile` returns it). HR can also set `preferred_communication_language` when
  creating/editing an employee.
* **Emails**: every mailable extends `App\Mail\BaseMailable`, which renders the email in the recipient
  employee's communication language (`App\Support\Locale\RecipientLocale` matches the address against the
  employee email, then the linked login email). Subjects and Blade templates use `__()`; shared wording is in
  `lang/<locale>/mail.php`, module wording in `lang/<locale>/mail_<module>.php`. Templates set
  `lang="{{ app()->getLocale() }}" dir="{{ \App\Support\Locale\SupportedLocales::dir() }}"` and align text
  with `$isRtl`. Recipients who are not employees (job applicants, external contacts) receive English.
  To force a language: `Mail::to($x)->locale('ar')->send(…)`.
* **Several employee recipients** (to-list and/or CC): one message takes the first "to" address's language, so
  send with `App\Support\Locale\LocalizedMail::send($to, $cc, fn () => new SomeMail(…))` — one message per
  language group, each in that group's language. Applicant copies are sent separately in English.
* In the `local` environment (or with `MAIL_FORCE_TEST_EMAIL=true`) every email, including CC/BCC, is redirected
  to `MAIL_TEST_EMAIL`.
* `User` implements `HasLocalePreference` (returns the linked employee's communication language), so Laravel
  notifications such as password reset follow it too.

## Adding a third language later

Add the code to `SUPPORTED_LANGS` (frontend) and `SupportedLocales::ALL` (backend), add `assets/i18n/<code>/`
and `lang/<code>/`, and the locale to `LANG_LOCALE` / `registerLocaleData`.
