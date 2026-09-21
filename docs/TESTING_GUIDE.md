# DemandFlow Bridge - Testing Guide

## Overview
This guide provides comprehensive testing procedures for DemandFlow Bridge navigation flow and role-based access controls.

## Test Environment
- **URL**: http://localhost:8000
- **Database**: Ensure database_schema.sql has been executed
- **Default Admin**: admin/admin123 (as defined in database_schema.sql)

## User Roles and Expected Access

### 1. Admin Role
**Access Level**: Full system access
**Test User**: admin / admin123

#### Accessible Pages:
- ✅ Admin Dashboard (`/admin-dashboard.php`)
- ✅ Manage Users (`/manage-users.php`)
- ✅ Bulk Upload (`/bulk-upload.php`)
- ✅ My Leads (`/my-leads.php`)
- ✅ QA Dashboard (`/qa.php`)
 - ✅ Leads Browser (`/leads-browser.php`)
- ✅ Export (`/export.php`)
- ✅ Agent Dashboard (`/agent-dashboard.php`)
- ✅ Form Filler Dashboard (`/form-filler-dashboard.php`)
- ✅ Agent (`/agent.php`)
- ✅ Form Filler (`/form-filler.php`)
- ✅ QA (`/qa.php`)
- ✅ Campaigns Manage (`/campaigns-manage.php`)
- ✅ Leads Edit (`/leads-edit.php`)

#### Navigation Test:
1. Login as admin
2. Verify navbar shows all menu items
3. Test each menu link works correctly
4. Verify no access denied messages

### 2. QA Role
**Access Level**: QA operations and lead review
**Test User**: Create via Manage Users

#### Accessible Pages:
- ✅ QA Dashboard (`/qa.php`)
 - ✅ QA (`/qa.php`)
 - ✅ Leads Browser (`/leads-browser.php`)
- ✅ Export (`/export.php`)
- ❌ Admin Dashboard (should redirect to access-denied.php)
- ❌ Manage Users (should redirect to access-denied.php)
- ❌ Bulk Upload (should redirect to access-denied.php)

#### Navigation Test:
1. Create QA user via admin panel
2. Login as QA user
3. Verify navbar shows only QA-relevant items
4. Test access to allowed pages
5. Test access denied for restricted pages

### 3. Agent Role
**Access Level**: Lead creation and management
**Test User**: Create via Manage Users

#### Accessible Pages:
- ✅ Agent Dashboard (`/agent-dashboard.php`)
- ✅ Agent (`/agent.php`)
- ✅ My Leads (`/my-leads.php`)
- ❌ QA Dashboard (should redirect to access-denied.php)
- ❌ Admin Dashboard (should redirect to access-denied.php)
- ❌ Manage Users (should redirect to access-denied.php)

#### Navigation Test:
1. Create Agent user via admin panel
2. Login as Agent user
3. Verify navbar shows only agent-relevant items
4. Test lead creation functionality
5. Test access denied for restricted pages

### 4. Form Filler Role
**Access Level**: Form completion operations
**Test User**: Create via Manage Users

#### Accessible Pages:
- ✅ Form Filler Dashboard (`/form-filler-dashboard.php`)
- ✅ Form Filler (`/form-filler.php`)
- ✅ Export (`/export.php`)
- ❌ Agent Dashboard (should redirect to access-denied.php)
- ❌ QA Dashboard (should redirect to access-denied.php)
- ❌ Admin Dashboard (should redirect to access-denied.php)

#### Navigation Test:
1. Create Form Filler user via admin panel
2. Login as Form Filler user
3. Verify navbar shows only form-filler-relevant items
4. Test form completion functionality
5. Test access denied for restricted pages

## Security Features Testing

### 1. Session Management
- [ ] Test session timeout (default: 30 minutes)
- [ ] Test session regeneration on login
- [ ] Test secure session cookies
- [ ] Test logout functionality

### 2. Login Security
- [ ] Test rate limiting (5 failed attempts)
- [ ] Test account lockout (15 minutes)
- [ ] Test password validation
- [ ] Test redirect after login

### 3. Access Control
- [ ] Test direct URL access without login
- [ ] Test role-based page restrictions
- [ ] Test data filtering by user role
- [ ] Test unauthorized API access

### 4. Input Validation
- [ ] Test SQL injection prevention
- [ ] Test XSS prevention
- [ ] Test CSRF protection
- [ ] Test file upload security

## UI/UX Testing

### 1. Table Standardization
- [ ] Verify consistent table styling across all pages
- [ ] Test table responsiveness on mobile devices
- [ ] Verify hover effects work consistently
- [ ] Test pagination functionality

### 2. Form Standardization
- [ ] Verify consistent form styling
- [ ] Test form validation messages
- [ ] Test form submission feedback
- [ ] Test responsive form layouts

### 3. Navigation Consistency
- [ ] Verify navbar appears consistently
- [ ] Test active menu highlighting
- [ ] Test responsive navigation menu
- [ ] Verify role-based menu items

## Database Testing

### 1. User Management
- [ ] Test user creation with different roles
- [ ] Test user role changes
- [ ] Test user deletion
- [ ] Test password reset functionality

### 2. Lead Management
- [ ] Test lead creation by agents
- [ ] Test lead assignment
- [ ] Test lead status updates
- [ ] Test lead filtering by role

### 3. Audit Trail
- [ ] Test login attempt logging
- [ ] Test access attempt logging
- [ ] Test user action logging
- [ ] Test session tracking

## Performance Testing

### 1. Page Load Times
- [ ] Test dashboard loading with large datasets
- [ ] Test table pagination performance
- [ ] Test search functionality speed
- [ ] Test export functionality performance

### 2. Database Queries
- [ ] Monitor query execution times
- [ ] Test with multiple concurrent users
- [ ] Verify proper indexing usage
- [ ] Test connection pooling

## Browser Compatibility

### Desktop Browsers
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)

### Mobile Browsers
- [ ] Chrome Mobile
- [ ] Safari Mobile
- [ ] Firefox Mobile

## Test Results Documentation

### Pass/Fail Criteria
- ✅ **Pass**: Feature works as expected
- ❌ **Fail**: Feature doesn't work or has issues
- ⚠️ **Warning**: Feature works but has minor issues

### Issue Reporting
For each failed test, document:
1. Test case description
2. Expected behavior
3. Actual behavior
4. Steps to reproduce
5. Browser/environment details
6. Screenshots if applicable

## Post-Testing Actions

### 1. Security Hardening
- [ ] Review and update security configurations
- [ ] Implement additional security measures if needed
- [ ] Update documentation based on findings

### 2. Performance Optimization
- [ ] Optimize slow-performing queries
- [ ] Implement caching where appropriate
- [ ] Optimize frontend assets

### 3. Documentation Updates
- [ ] Update user manuals
- [ ] Update deployment guides
- [ ] Update security documentation

## Conclusion

This testing guide ensures comprehensive validation of DemandFlow Bridge functionality, security, and user experience across all user roles and scenarios.
