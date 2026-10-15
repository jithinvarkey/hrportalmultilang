import { NgModule } from '@angular/core';
import { RouterModule, Routes } from '@angular/router';
import { AnnouncementsComponent } from './components/announcements.component';

const routes: Routes = [
  { path: '', component: AnnouncementsComponent },
];

@NgModule({
  imports: [RouterModule.forChild(routes)],
  exports: [RouterModule],
})
export class AnnouncementsRoutingModule {}
