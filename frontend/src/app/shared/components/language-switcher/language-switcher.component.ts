import { Component, Input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { MatTooltipModule } from '@angular/material/tooltip';
import { LanguageService } from '../../../core/services/language.service';

/**
 * EN / عربي toggle. `compact` renders a square icon-sized button (collapsed sidebar, mobile top bar).
 * Each label is written in its own language so users can always find their way back.
 */
@Component({
  selector: 'app-language-switcher',
  standalone: true,
  imports: [MatIconModule, MatTooltipModule],
  template: `
    @if (compact) {
      <button type="button" class="ls-compact" (click)="lang.toggle()"
              [matTooltip]="lang.isRtl() ? 'English' : 'العربية'"
              [attr.aria-label]="lang.isRtl() ? 'Switch to English' : 'التبديل إلى العربية'">
        <span [attr.lang]="lang.isRtl() ? 'en' : 'ar'">{{ lang.isRtl() ? 'EN' : 'ع' }}</span>
      </button>
    } @else {
      <div class="ls-seg" role="group" aria-label="Language / اللغة">
        <button type="button" [class.active]="!lang.isRtl()" (click)="lang.use('en')" lang="en"
                [attr.aria-pressed]="!lang.isRtl()">English</button>
        <button type="button" [class.active]="lang.isRtl()" (click)="lang.use('ar')" lang="ar"
                [attr.aria-pressed]="lang.isRtl()">العربية</button>
      </div>
    }
  `,
  styles: [`
    :host { display:inline-flex; }
    .ls-seg {
      display:inline-flex; width:100%; padding:3px; gap:2px; border-radius:9px;
      background:var(--surface2); border:1px solid var(--border);
    }
    .ls-seg button {
      flex:1; min-height:28px; padding:4px 10px; border:0; border-radius:7px;
      background:transparent; color:var(--text3); cursor:pointer;
      font-size:12px; font-weight:600; transition:background .15s, color .15s;
    }
    .ls-seg button[lang=ar] { font-family:'IBM Plex Sans Arabic', 'Tajawal', sans-serif; font-size:13px; }
    .ls-seg button:hover { color:var(--text); }
    .ls-seg button.active { background:var(--surface); color:var(--accent); box-shadow:0 1px 3px rgba(0,0,0,.15); }
    .ls-compact {
      width:30px; height:30px; border-radius:8px; border:1px solid var(--border2);
      background:transparent; color:var(--text2); cursor:pointer;
      display:flex; align-items:center; justify-content:center;
      font-size:12px; font-weight:700; transition:all .15s;
    }
    .ls-compact span[lang=ar] { font-family:'IBM Plex Sans Arabic', 'Tajawal', sans-serif; font-size:15px; line-height:1; }
    .ls-compact:hover { background:var(--surface2); color:var(--accent); }
  `],
})
export class LanguageSwitcherComponent {
  @Input() compact = false;
  constructor(public lang: LanguageService) {}
}
