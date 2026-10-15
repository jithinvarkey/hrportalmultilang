import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { ReactiveFormsModule, FormsModule } from '@angular/forms';
import { BidiModule } from '@angular/cdk/bidi';
import { TranslateModule } from '@ngx-translate/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatIconModule } from '@angular/material/icon';
import { MatSelectModule } from '@angular/material/select';
import { MatTableModule } from '@angular/material/table';
import { MatPaginatorModule } from '@angular/material/paginator';
import { MatSortModule } from '@angular/material/sort';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatSidenavModule } from '@angular/material/sidenav';
import { MatListModule } from '@angular/material/list';
import { MatMenuModule } from '@angular/material/menu';
import { MatDividerModule } from '@angular/material/divider';
import { MatBadgeModule } from '@angular/material/badge';
import { MatChipsModule } from '@angular/material/chips';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBarModule } from '@angular/material/snack-bar';
import { MatDialogModule } from '@angular/material/dialog';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatNativeDateModule } from '@angular/material/core';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatTooltipModule } from '@angular/material/tooltip';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MainLayoutComponent } from './components/main-layout/main-layout.component';
import { LanguageSwitcherComponent } from './components/language-switcher/language-switcher.component';
import { BilingualNameComponent } from './components/bilingual-name/bilingual-name.component';
import { ButtonClickLoadingDirective } from './directives/button-click-loading.directive';
import { I18N_PIPES } from './pipes/i18n.pipes';

const MATERIAL = [
  MatButtonModule, MatCardModule, MatFormFieldModule, MatInputModule,
  MatIconModule, MatSelectModule, MatTableModule, MatPaginatorModule,
  MatSortModule, MatToolbarModule, MatSidenavModule, MatListModule,
  MatMenuModule, MatDividerModule, MatBadgeModule, MatChipsModule,
  MatProgressBarModule, MatSnackBarModule, MatDialogModule,
  MatDatepickerModule, MatNativeDateModule, MatCheckboxModule,
  MatTooltipModule, MatProgressSpinnerModule
];

// Every feature module imports SharedModule, so the translate pipe, i18n pipes
// and the language switcher are available on every page.
const I18N = [TranslateModule, BidiModule, LanguageSwitcherComponent, BilingualNameComponent, ...I18N_PIPES];

@NgModule({
  declarations: [MainLayoutComponent],
  imports: [CommonModule, RouterModule, ReactiveFormsModule, FormsModule, ButtonClickLoadingDirective, ...I18N, ...MATERIAL],
  exports: [CommonModule, RouterModule, ReactiveFormsModule, FormsModule, MainLayoutComponent, ButtonClickLoadingDirective, ...I18N, ...MATERIAL]
})
export class SharedModule {}
