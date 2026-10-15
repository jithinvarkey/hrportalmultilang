import { NgModule } from '@angular/core';
import { SharedModule } from '../../shared/shared.module';
import { AnnouncementsRoutingModule } from './announcements-routing.module';
import { AnnouncementsComponent } from './components/announcements.component';

@NgModule({
  declarations: [AnnouncementsComponent],
  imports: [
    SharedModule,
    AnnouncementsRoutingModule,
  ],
})
export class AnnouncementsModule {}
