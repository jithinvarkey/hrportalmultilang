import { Component, OnInit, OnDestroy, HostListener } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Location } from '@angular/common';
import { NavigationEnd, Router } from '@angular/router';
import { Subscription, filter } from 'rxjs';
import { AuthService, NavItem } from '../../../core/services/auth.service';
import { ThemeService, THEMES } from '../../../core/services/theme.service';
import { LanguageService } from '../../../core/services/language.service';

const MOBILE_QUERY = '(max-width: 900px)';

export interface NavGroup { label: string; items: NavItem[]; }
export interface BottomNavItem { path: string; label: string; icon: string; }

/**
 * Mobile bottom bar: Home + three quick destinations + More (opens the full menu).
 * Employees get their self-service pages first; other portals get the pages they use most.
 * Only pages the user can see in the menu are offered (My Profile is open to everyone).
 */
const BOTTOM_NAV_PREFERENCE: Record<string, string[]> = {
  employee: ['/leave', '/loans', '/profile', '/requests'],
  manager:  ['/leave', '/requests', '/profile', '/employees'],
  finance:  ['/payroll', '/loans', '/leave', '/profile'],
  hr:       ['/employees', '/leave', '/payroll', '/attendance', '/profile'],
  admin:    ['/employees', '/leave', '/payroll', '/attendance', '/profile'],
};
const PROFILE_NAV: BottomNavItem = { path: '/profile', label: 'LAYOUT.PROFILE', icon: 'person' };

@Component({
  standalone: false,
  selector: 'app-main-layout',
  templateUrl: './main-layout.component.html',
  styleUrls: ['./main-layout.component.scss'],
})
export class MainLayoutComponent implements OnInit, OnDestroy {
  sidebarOpen = true;
  /** ≤900px: sidebar becomes an off-canvas drawer opened from the top bar. */
  isMobile = false;
  mobileNavOpen = false;
  private mql?: MediaQueryList;
  private routerSub?: Subscription;
  private onMqlChange = (e: MediaQueryListEvent) => { this.isMobile = e.matches; if (!e.matches) this.mobileNavOpen = false; };
  navGroups:  NavGroup[] = [];
  bottomNav:  BottomNavItem[] = [];
  /** Current page, for the mobile top bar title and back button. */
  currentUrl = '';
  user:       any = null;
  portalType  = 'employee';
  readonly themes = THEMES;

  // Notifications (bell)
  notifications: any[] = [];
  unreadCount = 0;
  showNotifications = false;
  private pollHandle: any = null;

  portalLabels: Record<string, { label: string; icon: string; color: string }> = {
    admin:    { label: 'LAYOUT.PORTAL.ADMIN',    icon: 'shield',             color: '#ef4444' },
    hr:       { label: 'LAYOUT.PORTAL.HR',       icon: 'manage_accounts',    color: '#6366f1' },
    finance:  { label: 'LAYOUT.PORTAL.FINANCE',  icon: 'account_balance',    color: '#10b981' },
    manager:  { label: 'LAYOUT.PORTAL.MANAGER',  icon: 'supervisor_account', color: '#f59e0b' },
    employee: { label: 'LAYOUT.PORTAL.EMPLOYEE', icon: 'person',             color: '#3b82f6' },
  };

  constructor(public auth: AuthService, public themeService: ThemeService, public lang: LanguageService, private router: Router,
              private http: HttpClient, private location: Location) {}

  ngOnInit(): void {
    this.mql = window.matchMedia(MOBILE_QUERY);
    this.isMobile = this.mql.matches;
    this.mql.addEventListener('change', this.onMqlChange);
    this.routerSub = this.router.events.pipe(filter(e => e instanceof NavigationEnd))
      .subscribe((e: any) => { this.currentUrl = e.urlAfterRedirects; this.mobileNavOpen = false; this.showNotifications = false; });
    this.currentUrl = this.router.url;

    this.loadNav();
    this.auth.refreshUser().subscribe({
      next: () => this.loadNav(),
      error: () => {},
    });
    this.loadNotifications();
    // Poll every 60s for new notifications.
    this.pollHandle = setInterval(() => this.loadNotifications(), 60000);
  }

  ngOnDestroy(): void {
    if (this.pollHandle) clearInterval(this.pollHandle);
    this.mql?.removeEventListener('change', this.onMqlChange);
    this.routerSub?.unsubscribe();
  }

  @HostListener('document:keydown.escape')
  onEscape(): void { this.mobileNavOpen = false; this.showNotifications = false; }

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

  private loadNav(): void {
    this.user       = this.auth.getUser();
    this.portalType = this.auth.getPortalType();
    const visible   = this.auth.getVisibleNavItems();
    this.navGroups  = this.buildNavGroups(visible);
    this.bottomNav  = this.buildBottomNav(visible);
  }

  private buildBottomNav(visible: NavItem[]): BottomNavItem[] {
    const available = new Map<string, BottomNavItem>(visible.map(i => [i.path, { path: i.path, label: i.label, icon: i.icon }]));
    available.set(PROFILE_NAV.path, PROFILE_NAV);
    const preference = BOTTOM_NAV_PREFERENCE[this.portalType] ?? BOTTOM_NAV_PREFERENCE['employee'];
    const picked = preference.map(p => available.get(p)).filter((i): i is BottomNavItem => !!i).slice(0, 3);
    return [{ path: '/dashboard', label: 'LAYOUT.HOME', icon: 'home' }, ...picked];
  }

  /** Bottom-bar item for the current page (also matches its sub-pages, e.g. /leave/123). */
  isBottomActive(path: string): boolean {
    const url = this.currentUrl.split(/[?#]/)[0];
    return url === path || url.startsWith(path + '/');
  }

  get isHome(): boolean { return this.isBottomActive('/dashboard') || this.currentUrl.split(/[?#]/)[0] === '/'; }

  /** Title for the mobile top bar: the menu label of the current page. */
  get pageTitle(): string {
    if (this.isBottomActive('/profile')) return PROFILE_NAV.label;
    const url = this.currentUrl.split(/[?#]/)[0];
    const match = this.navGroups.flatMap(g => g.items)
      .filter(i => url === i.path || url.startsWith(i.path + '/'))
      .sort((a, b) => b.path.length - a.path.length)[0];
    return match?.label ?? 'HRMS';
  }

  goBack(): void {
    if (window.history.length > 1) this.location.back();
    else this.router.navigate(['/dashboard']);
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
    const role = this.auth.getUserRole();
    const key = 'ROLES.' + role;
    const label = this.lang.t(key);
    return label !== key ? label : role;
  }

  setTheme(id: string): void { this.themeService.set(id); }
  cycleTheme(): void         { this.themeService.cycle(); }
  goToProfile(): void        { this.router.navigate(['/profile']); }
  logout(): void             { this.auth.logout(); this.router.navigate(['/auth/login']); }
  toggleSidebar(): void {
    if (this.isMobile) { this.mobileNavOpen = !this.mobileNavOpen; return; }
    this.sidebarOpen = !this.sidebarOpen;
  }
  closeMobileNav(): void     { this.mobileNavOpen = false; }

  /** On mobile the drawer always shows the full (expanded) sidebar. */
  get expanded(): boolean    { return this.isMobile || this.sidebarOpen; }
}
