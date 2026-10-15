import { Component, OnInit, OnDestroy } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { AuthService, NavItem } from '../../../core/services/auth.service';
import { ThemeService, THEMES } from '../../../core/services/theme.service';

export interface NavGroup { label: string; items: NavItem[]; }

@Component({
  standalone: false,
  selector: 'app-main-layout',
  templateUrl: './main-layout.component.html',
  styleUrls: ['./main-layout.component.scss'],
})
export class MainLayoutComponent implements OnInit, OnDestroy {
  sidebarOpen = true;
  navGroups:  NavGroup[] = [];
  user:       any = null;
  portalType  = 'employee';
  readonly themes = THEMES;

  // Notifications (bell)
  notifications: any[] = [];
  unreadCount = 0;
  showNotifications = false;
  private pollHandle: any = null;

  portalLabels: Record<string, { label: string; icon: string; color: string }> = {
    admin:    { label: 'Admin Portal',    icon: 'shield',             color: '#ef4444' },
    hr:       { label: 'HR Portal',       icon: 'manage_accounts',    color: '#6366f1' },
    finance:  { label: 'Finance Portal',  icon: 'account_balance',    color: '#10b981' },
    manager:  { label: 'Manager Portal',  icon: 'supervisor_account', color: '#f59e0b' },
    employee: { label: 'Employee Portal', icon: 'person',             color: '#3b82f6' },
  };

  constructor(public auth: AuthService, public themeService: ThemeService, private router: Router, private http: HttpClient) {}

  ngOnInit(): void {
    this.user       = this.auth.getUser();
    this.portalType = this.auth.getPortalType();
    this.navGroups  = this.buildNavGroups(this.auth.getVisibleNavItems());
    this.auth.refreshUser().subscribe({
      next: () => {
        this.user = this.auth.getUser();
        this.portalType = this.auth.getPortalType();
        this.navGroups = this.buildNavGroups(this.auth.getVisibleNavItems());
      },
      error: () => {},
    });
    this.loadNotifications();
    // Poll every 60s for new notifications.
    this.pollHandle = setInterval(() => this.loadNotifications(), 60000);
  }

  ngOnDestroy(): void {
    if (this.pollHandle) clearInterval(this.pollHandle);
  }

  loadNotifications(): void {
    this.http.get<any>('/api/v1/notifications', { params: { limit: 20 } }).subscribe({
      next: r => { this.notifications = r?.notifications || []; this.unreadCount = r?.unread_count || 0; },
      error: () => {},
    });
  }

  toggleNotifications(): void {
    this.showNotifications = !this.showNotifications;
  }

  openNotification(n: any): void {
    if (!n.read_at) {
      this.http.post(`/api/v1/notifications/${n.id}/read`, {}).subscribe({ next: () => {}, error: () => {} });
      n.read_at = new Date().toISOString();
      this.unreadCount = Math.max(0, this.unreadCount - 1);
    }
    this.showNotifications = false;
    if (n.link) this.router.navigateByUrl(n.link);
  }

  markAllNotificationsRead(): void {
    this.http.post('/api/v1/notifications/read-all', {}).subscribe({
      next: () => { this.notifications.forEach(n => n.read_at = n.read_at || new Date().toISOString()); this.unreadCount = 0; },
      error: () => {},
    });
  }

  private buildNavGroups(items: NavItem[]): NavGroup[] {
    const groups: NavGroup[] = []; let current: NavGroup | null = null;
    for (const item of items) {
      if (item.group)  { current = { label: item.group, items: [item] }; groups.push(current); }
      else if (current){ current.items.push(item); }
      else             { current = { label: '', items: [item] }; groups.push(current); }
    }
    return groups;
  }

  get portalInfo() { return this.portalLabels[this.portalType] ?? this.portalLabels['employee']; }

  get userInitials(): string {
    return (this.user?.name||'').split(' ').map((w: string)=>w[0]).slice(0,2).join('').toUpperCase();
  }

  get roleLabel(): string {
    const map: Record<string,string> = {
      super_admin:'Super Admin',ceo:'CEO',hr_manager:'HR Manager',hr_staff:'HR Staff',
      finance_manager:'Finance Manager',department_manager:'Dept. Manager',employee:'Employee',
    };
    return map[this.auth.getUserRole()] ?? this.auth.getUserRole();
  }

  setTheme(id: string): void { this.themeService.set(id); }
  cycleTheme(): void         { this.themeService.cycle(); }
  goToProfile(): void        { this.router.navigate(['/profile']); }
  logout(): void             { this.auth.logout(); this.router.navigate(['/auth/login']); }
  toggleSidebar(): void      { this.sidebarOpen = !this.sidebarOpen; }
}
