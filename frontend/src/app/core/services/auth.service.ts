import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { tap } from 'rxjs/operators';

export const ROLES = {
  SUPER_ADMIN: 'super_admin',
  CEO: 'ceo',
  HR_MANAGER: 'hr_manager',
  HR_STAFF: 'hr_staff',
  IT_MANAGER: 'it_manager',
  IT_SUPERVISOR: 'it_supervisor',
  CYBERSECURITY_OFFICER: 'cybersecurity_officer',
  FINANCE_MANAGER: 'finance_manager',
  DEPT_MANAGER: 'department_manager',
  EMPLOYEE: 'employee',
} as const;

export interface NavItem {
  path: string;
  label: string;
  icon: string;
  group?: string;
  roles?: string[];
  perms?: string[];
  excludePortal?: string[];
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private apiUrl = '/api/v1';
  private tokenKey = 'hrms_token';
  private userKey = 'hrms_user';

  constructor(private http: HttpClient) { }

  login(login: string, password: string): Observable<any> {
    const identifier = login.trim();
    const payload = identifier.includes('@')
      ? { email: identifier, login: identifier, password }
      : { login: identifier, password };

    return this.http.post<any>(`${this.apiUrl}/auth/login`, payload,
      { headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' } }
    ).pipe(
      tap(res => {
        if (res.token) sessionStorage.setItem(this.tokenKey, res.token);
        if (res.user) sessionStorage.setItem(this.userKey, JSON.stringify(res.user));
      })
    );
  }

  verifyLoginOtp(challengeToken: string, otp: string): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/auth/verify-login-otp`, {
      challenge_token: challengeToken,
      otp,
    }).pipe(
      tap(res => {
        if (res.token) sessionStorage.setItem(this.tokenKey, res.token);
        if (res.user) sessionStorage.setItem(this.userKey, JSON.stringify(res.user));
      })
    );
  }

  resendOtp(challengeToken: string, purpose: 'login' | 'password_reset'): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/auth/resend-otp`, {
      challenge_token: challengeToken,
      purpose,
    });
  }

  forgotPassword(email: string): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/auth/forgot-password`, { email });
  }

  resetPasswordWithOtp(challengeToken: string, otp: string, password: string, passwordConfirmation: string): Observable<any> {
    return this.http.post<any>(`${this.apiUrl}/auth/reset-password`, {
      challenge_token: challengeToken,
      otp,
      password,
      password_confirmation: passwordConfirmation,
    });
  }


  logout(): void {

  const token = this.getToken();

  this.http.post(
    `${this.apiUrl}/auth/logout`,
    {},
    {
      headers: {
        Authorization: `Bearer ${token}`
      }
    }
  ).subscribe({
    next: () => {
      this.clearStoredSession();
      //this.router.navigate(['/login']);
    },
    error: () => {
      // Even if API fails, clear local session
      this.clearStoredSession();
      //this.router.navigate(['/login']);
    }
  });

}

  refreshUser(): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/auth/me`).pipe(
      tap(res => { if (res.user) sessionStorage.setItem(this.userKey, JSON.stringify(res.user)); })
    );
  }

  // ── Identity ──────────────────────────────────────────────────────────
  getToken(): string | null { return sessionStorage.getItem(this.tokenKey) || localStorage.getItem(this.tokenKey); }
  isLoggedIn(): boolean { return !!this.getToken(); }
  getUser(): any {
    const u = sessionStorage.getItem(this.userKey) || localStorage.getItem(this.userKey);
    return u ? JSON.parse(u) : null;
  }

  private clearStoredSession(): void {
    sessionStorage.removeItem(this.tokenKey);
    sessionStorage.removeItem(this.userKey);
    localStorage.removeItem(this.tokenKey);
    localStorage.removeItem(this.userKey);
  }

  getRoles(): string[] {
    const roles = this.getUser()?.roles;
    if (!roles) return [];
    // Spatie getRoleNames() can serialise as an object {"0":"super_admin"} instead
    // of a plain array. Normalise both shapes so hasRole() works correctly.
    if (Array.isArray(roles)) return roles;
    return Object.values(roles);
  }
  getUserRole(): string { return this.getRoles()[0] || ''; }
  getPermissions(): string[] { return this.getUser()?.permissions || []; }

  hasRole(role: string): boolean {
    const roles = this.getRoles();
    return roles.includes(role)
      || (role === ROLES.HR_MANAGER && roles.includes(ROLES.CEO));
  }
  hasAnyRole(roles: string[]): boolean { return roles.some(r => this.hasRole(r)); }
  can(permission: string): boolean { return this.getPermissions().includes(permission); }
  canAny(perms: string[]): boolean { return perms.some(p => this.can(p)); }

  isSuperAdmin(): boolean { return this.hasRole(ROLES.SUPER_ADMIN); }
  isCEO(): boolean { return this.hasRole(ROLES.CEO); }
  isHRManager(): boolean { return this.hasRole(ROLES.HR_MANAGER); }
  isHRStaff(): boolean { return this.hasRole(ROLES.HR_STAFF); }
  isFinanceManager(): boolean { return this.hasRole(ROLES.FINANCE_MANAGER); }
  isDeptManager(): boolean { return this.hasRole(ROLES.DEPT_MANAGER); }
  isEmployee(): boolean { return this.hasRole(ROLES.EMPLOYEE); }

  isHRRole(): boolean { return this.hasAnyRole([ROLES.SUPER_ADMIN, ROLES.CEO, ROLES.HR_MANAGER, ROLES.HR_STAFF]); }
  isAdminRole(): boolean { return this.hasAnyRole([ROLES.SUPER_ADMIN, ROLES.CEO, ROLES.HR_MANAGER]); }
  isManagerRole(): boolean { return this.hasAnyRole([ROLES.SUPER_ADMIN, ROLES.CEO, ROLES.HR_MANAGER, ROLES.HR_STAFF, ROLES.DEPT_MANAGER]); }

  isITDepartmentManager(): boolean {
    const employee = this.getUser()?.employee || {};
    return String(employee.departmentCode || '').trim().toUpperCase() === 'IT'
      && employee.isDepartmentManager === true;
  }

  canViewAllAssets(): boolean {
    return this.hasAnyRole([ROLES.SUPER_ADMIN, ROLES.CEO, ROLES.HR_MANAGER]) || this.isITDepartmentManager();
  }

  canManageAssets(): boolean {
    return this.hasRole(ROLES.SUPER_ADMIN) || this.isITDepartmentManager();
  }

  hasAssetManagementAccess(): boolean {
    return this.canViewAllAssets();
  }

  getPortalType(): 'admin' | 'hr' | 'finance' | 'manager' | 'employee' {
    if (this.isSuperAdmin()) return 'admin';
    if (this.isCEO()) return 'hr';
    if (this.isHRManager()) return 'hr';
    if (this.isHRStaff()) return 'hr';
    if (this.isFinanceManager()) return 'finance';
    if (this.isDeptManager()) return 'manager';
    return 'employee';
  }

  getVisibleNavItems(): NavItem[] {
    const u = this.getUser();
    if (!u) return [];
    const portal = this.getPortalType();

    const all: NavItem[] = [
      // ── Overview ──────────────────────────────────────────────────────
      {
        group: 'Overview',
        path: '/dashboard', label: 'Dashboard', icon: 'dashboard',
        roles: []
      },

      // ── People ────────────────────────────────────────────────────────
      {
        group: 'People',
        path: '/employees', label: 'Employees', icon: 'people',
        perms: ['employees.view']
      },
      {
        path: '/org-chart', label: 'Org Chart', icon: 'account_tree',
        perms: ['orgchart.view']
      },
      // ── HR & Workforce ────────────────────────────────────────────────
      {
        group: 'HR & Workforce',
        path: '/attendance', label: 'Attendance', icon: 'fingerprint',
        roles: [ROLES.SUPER_ADMIN, ROLES.HR_MANAGER, ROLES.HR_STAFF,
        ROLES.FINANCE_MANAGER, ROLES.DEPT_MANAGER, ROLES.EMPLOYEE]
      },
      {
        path: '/attendance/log', label: 'Attendance Log', icon: 'list_alt',
        roles: [ROLES.SUPER_ADMIN, ROLES.HR_MANAGER, ROLES.HR_STAFF,
        ROLES.DEPT_MANAGER]
      },
      {
        path: '/attendance/biotime', label: 'BioTime Sync', icon: 'fingerprint',
        roles: [ROLES.SUPER_ADMIN, ROLES.HR_MANAGER, ROLES.HR_STAFF]
      },
      {
        path: '/leave', label: 'Leave', icon: 'event_available',
        roles: [ROLES.FINANCE_MANAGER],
        perms: ['leave.view_all', 'leave.view_own', 'leave.request']
      },
      {
        path: '/contracts', label: 'Contracts', icon: 'description',
        roles: [ROLES.SUPER_ADMIN, ROLES.HR_MANAGER, ROLES.HR_STAFF,
        ROLES.FINANCE_MANAGER, ROLES.DEPT_MANAGER, ROLES.EMPLOYEE]
      },
      {
        path: '/separations', label: 'Separations', icon: 'exit_to_app',
        roles: [ROLES.SUPER_ADMIN, ROLES.CEO, ROLES.HR_MANAGER, ROLES.HR_STAFF,
        ROLES.FINANCE_MANAGER, ROLES.DEPT_MANAGER, ROLES.EMPLOYEE]
      },
      {
        path: '/recruitment', label: 'Recruitment', icon: 'work',
        roles: [ROLES.SUPER_ADMIN, ROLES.HR_MANAGER, ROLES.HR_STAFF, ROLES.DEPT_MANAGER, ROLES.EMPLOYEE]
      },
      {
        path: '/performance', label: 'Performance', icon: 'leaderboard',
        perms: ['performance.view']
      },

      // ── Finance ───────────────────────────────────────────────────────
      {
        group: 'Finance',
        path: '/payroll', label: 'Payroll', icon: 'payments',
        roles: [ROLES.SUPER_ADMIN, ROLES.HR_MANAGER]
      },
      {
        path: '/loans', label: 'Loans', icon: 'account_balance',
        perms: ['loans.view_all', 'loans.view_own', 'loans.request']
      },

      // ── Requests ──────────────────────────────────────────────────────
      // Single entry — the module itself shows "My Requests" tab for employees
      // and "All Requests" tab for HR/managers via internal role detection.
      {
        group: 'Requests',
        path: '/requests', label: 'Requests', icon: 'inbox',
        perms: ['requests.view_own', 'requests.submit', 'requests.view_all']
      },

      // ── Administration ────────────────────────────────────────────────
      {
        group: 'Communications',
        path: '/announcements', label: 'Announcements', icon: 'campaign',
        roles: []
      },
      {
        path: '/policies', label: 'Policies', icon: 'policy',
        roles: []
      },
      {
        group: 'Administration',
        path: '/admin', label: 'Admin', icon: 'admin_panel_settings',
        perms: ['admin.manage_users', 'admin.manage_roles']
      },
      {
        path: '/assets', label: 'Asset Management', icon: 'inventory_2',
        roles: [
          ROLES.SUPER_ADMIN,
          ROLES.HR_MANAGER,
          ROLES.HR_STAFF,
          ROLES.IT_MANAGER,
          ROLES.IT_SUPERVISOR,
          ROLES.CYBERSECURITY_OFFICER,
          ROLES.EMPLOYEE,
        ]
      },
    ];

    const seen = new Set<string>();
    const filtered: NavItem[] = [];

    for (const item of all) {
      const key = item.path + item.label;
      if (seen.has(key)) continue;
      if (item.excludePortal?.includes(portal)) continue;

      const noRestriction = !item.perms?.length && !item.roles?.length;
      const roleMatch = item.path === '/assets'
        ? this.hasAssetManagementAccess() || this.hasRole(ROLES.EMPLOYEE)
        : item.roles?.length && this.hasAnyRole(item.roles);
      const permMatch = item.perms?.length && this.canAny(item.perms);

      if (noRestriction || roleMatch || permMatch) {
        seen.add(key);
        filtered.push(item);
      }
    }

    return filtered;
  }
}
