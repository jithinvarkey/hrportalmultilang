import { Component } from '@angular/core';
import { LanguageService } from './core/services/language.service';

// The [dir] wrapper feeds Angular Material/CDK (menus, selects, tooltips, dialogs) the active direction.
@Component({
  standalone: false,
  selector: 'app-root',
  template: '<div class="app-dir-root" [dir]="lang.dir()"><router-outlet></router-outlet></div>'
})
export class AppComponent {
  constructor(public lang: LanguageService) {}
}
