import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClientModule } from '@angular/common/http';
import { RouterModule, Routes } from '@angular/router';
import { SharedModule } from '../../shared/shared.module';
import { AssetsComponent } from './components/assets.component';

const routes: Routes = [{ path: '', component: AssetsComponent }];

@NgModule({
  declarations: [AssetsComponent],
  imports: [CommonModule, HttpClientModule, SharedModule, RouterModule.forChild(routes)],
})
export class AssetsModule {}
