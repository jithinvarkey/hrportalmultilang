import { NgModule } from '@angular/core';
import { RouterModule, Routes } from '@angular/router';
import { AuthGuard } from './core/guards/auth.guard';
import { RoleGuard } from './core/guards/role.guard';
import { MainLayoutComponent } from './shared/components/main-layout/main-layout.component';

const routes: Routes = [
  { path: '', redirectTo: '/dashboard', pathMatch: 'full' },
  {
    path: 'auth',
    loadChildren: () => import('./modules/auth/auth.module').then(m => m.AuthModule)
  },
  {
    path: 'public',
    loadChildren: () => import('./modules/auth/auth.module').then(m => m.AuthModule)
  },
  {
    path: '',
    component: MainLayoutComponent,
    canActivate: [AuthGuard],
    children: [
      {
        path: 'dashboard',
        loadChildren: () => import('./modules/dashboard/dashboard.module').then(m => m.DashboardModule)
      },
      {
        path: 'employees',
        loadChildren: () => import('./modules/employees/employees.module').then(m => m.EmployeesModule)
      },
      {
        path: 'payroll',
        loadChildren: () => import('./modules/payroll/payroll.module').then(m => m.PayrollModule),
        canActivate: [RoleGuard],
        data: { roles: ['super_admin', 'ceo', 'hr_manager'] }
      },
      {
        path: 'leave',
        loadChildren: () => import('./modules/leave/leave.module').then(m => m.LeaveModule)
      },
      // ── Attendance (new) ────────────────────────────────────────────────
      {
        path: 'attendance',
        loadChildren: () => import('./modules/attendance/attendance.module').then(m => m.AttendanceModule)
      },
      {
        path: 'recruitment',
        loadChildren: () => import('./modules/recruitment/recruitment.module').then(m => m.RecruitmentModule)
      },
      {
        path: 'performance',
        loadChildren: () => import('./modules/performance/performance.module').then(m => m.PerformanceModule)
      },
      {
        path: 'org-chart',
        loadChildren: () => import('./modules/org-chart/org-chart.module').then(m => m.OrgChartModule)
      },
      {
        path: 'admin',
        loadChildren: () => import('./modules/admin/admin.module').then(m => m.AdminModule),
        canActivate: [RoleGuard],
        data: { perms: ['admin.manage_users', 'admin.manage_roles'] }
      },
      {
        path: 'requests',
        loadChildren: () => import('./modules/requests/requests.module').then(m => m.RequestsModule)
      },
      {
        path: 'announcements',
        loadChildren: () => import('./modules/announcements/announcements.module').then(m => m.AnnouncementsModule)
      },
      {
        path: 'policies',
        loadChildren: () => import('./modules/policies/policies.module').then(m => m.PoliciesModule)
      },
      {
        path: 'separations',
        loadChildren: () => import('./modules/separations/separations.module').then(m => m.SeparationsModule)
      },
      {
        path: 'loans',
        loadChildren: () => import('./modules/loans/loans.module').then(m => m.LoansModule)
      },
      {
        path: 'contracts',
        loadChildren: () => import('./modules/contracts/contracts.module').then(m => m.ContractsModule)
      },
      {
        path: 'profile',
        loadChildren: () => import('./modules/profile/profile.module').then(m => m.ProfileModule)
      },
      {
        path: 'reports',
        loadChildren: () => import('./modules/reports/reports.module').then(m => m.ReportsModule)
      },
      {
        path: 'assets',
        loadChildren: () => import('./modules/assets/assets.module').then(m => m.AssetsModule),
        canActivate: [RoleGuard],
        data: {
          roles: [
            'super_admin',
            'ceo',
            'hr_manager',
            'hr_staff',
            'it_manager',
            'it_supervisor',
            'cybersecurity_officer',
            'department_manager',
            'employee',
          ]
        }
      }
    ]
  },
  { path: '**', redirectTo: '/dashboard' }
];

@NgModule({
  imports: [RouterModule.forRoot(routes)],
  exports: [RouterModule]
})
export class AppRoutingModule {}
