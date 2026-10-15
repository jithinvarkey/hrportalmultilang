import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { NO_ERRORS_SCHEMA } from '@angular/core';

import { MainLayoutComponent } from './main-layout.component';
import { AuthService } from '../../../core/services/auth.service';
import { ThemeService } from '../../../core/services/theme.service';

/**
 * Unit tests for MainLayoutComponent's user/profile area.
 *
 * Regression: the sidebar showed the user's name but had no link to the
 * profile page. goToProfile() now navigates to /profile.
 *
 * @group layout
 */
describe('MainLayoutComponent — profile link', () => {
  let component: MainLayoutComponent;
  let fixture: ComponentFixture<MainLayoutComponent>;
  let router: jasmine.SpyObj<Router>;

  const authStub = {
    getUser: () => ({ name: 'Jane Doe', roles: ['hr_manager'] }),
    getPortalType: () => 'hr',
    getVisibleNavItems: () => [],
    getUserRole: () => 'hr_manager',
    logout: jasmine.createSpy('logout'),
  } as unknown as AuthService;

  const themeStub = {
    current: () => 'light',
    set: jasmine.createSpy('set'),
    cycle: jasmine.createSpy('cycle'),
  } as unknown as ThemeService;

  beforeEach(async () => {
    const routerSpy = jasmine.createSpyObj('Router', ['navigate']);

    await TestBed.configureTestingModule({
      declarations: [MainLayoutComponent],
      providers: [
        { provide: AuthService, useValue: authStub },
        { provide: ThemeService, useValue: themeStub },
        { provide: Router, useValue: routerSpy },
      ],
      schemas: [NO_ERRORS_SCHEMA],
    }).compileComponents();

    fixture = TestBed.createComponent(MainLayoutComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router) as jasmine.SpyObj<Router>;
    fixture.detectChanges();
  });

  it('navigates to /profile from goToProfile()', () => {
    component.goToProfile();
    expect(router.navigate).toHaveBeenCalledWith(['/profile']);
  });

  it('derives user initials from the name', () => {
    expect(component.userInitials).toBe('JD');
  });

  it('maps the role to a friendly label', () => {
    expect(component.roleLabel).toBe('HR Manager');
  });

  it('logout signs out and routes to login', () => {
    component.logout();
    expect(authStub.logout).toHaveBeenCalled();
    expect(router.navigate).toHaveBeenCalledWith(['/auth/login']);
  });
});
