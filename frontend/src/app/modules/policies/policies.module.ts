import { NgModule } from '@angular/core';
import { SharedModule } from '../../shared/shared.module';
import { PoliciesRoutingModule } from './policies-routing.module';
import { PoliciesComponent } from './components/policies.component';

@NgModule({
  declarations: [PoliciesComponent],
  imports: [
    SharedModule,
    PoliciesRoutingModule,
  ],
})
export class PoliciesModule {}
