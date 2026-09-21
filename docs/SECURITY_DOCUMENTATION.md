# DemandFlow Bridge - Security Documentation

## Overview

This document provides comprehensive security documentation for the DemandFlow Bridge authentication and authorization framework. The system implements multiple layers of security to protect user data and ensure proper access control.

## Table of Contents

1. [Authentication System](#authentication-system)
2. [Authorization Framework](#authorization-framework)
3. [Session Management](#session-management)
4. [Security Features](#security-features)
5. [Database Security](#database-security)
6. [Input Validation](#input-validation)
7. [Security Configuration](#security-configuration)
8. [Monitoring and Logging](#monitoring-and-logging)
9. [Security Best Practices](#security-best-practices)
10. [Incident Response](#incident-response)

## Authentication System

### Core Authentication Functions

The authentication system is centralized in `includes/auth.php` and provides the following core functions:

#### `loginUser($username, $password)`
- **Purpose**: Authenticates user credentials and establishes secure session
- **Security Features**:
  - Password verification using PHP's `password_verify()`
  - Rate limiting (5 attempts per 15 minutes)
  - Account lockout mechanism
  - Login attempt logging
  - Session regeneration on successful login
  - Secure session cookie configuration

#### `isLoggedIn()`
- **Purpose**: Validates current user session
- **Security Features**:
  - Session timeout validation (30 minutes of inactivity)
  - Session integrity checks
  - Automatic session cleanup

#### `getCurrentUser()`
- **Purpose**: Retrieves current user information
- **Security Features**:
  - Session validation before data retrieval
  - Sanitized user data output
  - Database connection security

### Password Security

- **Hashing**: Uses PHP's `password_hash()` with `PASSWORD_DEFAULT` algorithm
- **Verification**: Uses `password_verify()` for secure comparison
- **Storage**: Passwords are never stored in plain text
- **Requirements**: Configurable password complexity requirements

### Account Security

#### Account Lockout
- **Trigger**: 5 failed login attempts within 15 minutes
- **Duration**: Account locked for 15 minutes
- **Implementation**: `isAccountLocked()` function checks lockout status
- **Recovery**: Automatic unlock after timeout period

#### Rate Limiting
- **Scope**: Per-username rate limiting
- **Threshold**: 5 attempts per 15-minute window
- **Storage**: `login_attempts` table tracks attempts
- **Cleanup**: Automatic cleanup of old attempt records

## Authorization Framework

### Role-Based Access Control (RBAC)

The system implements a comprehensive RBAC system with four distinct roles:

#### Role Hierarchy
1. **Admin** - Full system access
2. **QA** - Quality assurance operations
3. **Agent** - Lead creation and management
4. **Form Filler** - Form completion operations

#### Role Functions

##### `hasRole($role)`
- **Purpose**: Checks if current user has specific role
- **Security**: Validates session before role check
- **Usage**: Foundation for all authorization decisions

##### `requireRole($roles)`
- **Purpose**: Enforces role-based access control
- **Features**:
  - Accepts single role or array of roles
  - Automatic redirection to access denied page
  - Logging of unauthorized access attempts
  - Session validation before role check

##### Role-Specific Functions
- `isAdmin()` - Checks for admin role
- `isQA()` - Checks for QA role
- `isAgent()` - Checks for agent role
- `isFormFiller()` - Checks for form filler role

### Resource-Based Access Control

#### `canAccess($resource, $action = 'read')`
Provides granular access control for specific resources:

##### Supported Resources:
- **leads**: Lead data access
- **users**: User management
- **campaigns**: Campaign management
- **reports**: Report generation

##### Access Matrix:
```
Resource    | Admin | QA    | Agent | Form Filler
------------|-------|-------|-------|------------
leads       | CRUD  | RU    | CRUD* | RU*
users       | CRUD  | -     | -     | -
campaigns   | CRUD  | R     | R     | R
reports     | CRUD  | R     | R*    | R*
```
*Limited to own data unless admin

## Session Management

### Secure Session Configuration

#### Session Security Settings
```php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict'
]);
```

#### Session Features
- **HTTPOnly Cookies**: Prevents XSS access to session cookies
- **Secure Cookies**: HTTPS-only transmission when available
- **SameSite Protection**: CSRF protection via SameSite=Strict
- **Session Regeneration**: New session ID on login
- **Timeout Management**: 30-minute inactivity timeout

### Session Data Structure
```php
$_SESSION = [
    'user_id' => int,
    'username' => string,
    'role' => string,
    'login_time' => timestamp,
    'last_activity' => timestamp,
    'redirect_after_login' => string (optional)
];
```

### Session Lifecycle

1. **Login**: Session created with secure parameters
2. **Activity**: Last activity timestamp updated
3. **Timeout**: Session invalidated after 30 minutes inactivity
4. **Logout**: Session destroyed and cookies cleared
5. **Regeneration**: Session ID regenerated periodically

## Security Features

### Login Protection

#### Brute Force Protection
- **Rate Limiting**: 5 attempts per 15 minutes per username
- **Account Lockout**: Temporary account suspension
- **Progressive Delays**: Increasing delays between attempts
- **IP Tracking**: Monitor attempts by IP address

#### Login Attempt Logging
All login attempts are logged with:
- Username
- IP address
- Timestamp
- Success/failure status
- User agent information

### Access Control Protection

#### Page-Level Protection
- **requireLogin()**: Ensures user authentication
- **requireRole()**: Enforces role-based access
- **Automatic Redirection**: Seamless user experience
- **Access Logging**: Track unauthorized access attempts

#### Data-Level Protection
- **User Data Filtering**: Users see only their own data (unless admin)
- **Query Parameterization**: Prevents SQL injection
- **Input Sanitization**: XSS prevention
- **Output Encoding**: Safe data display

### CSRF Protection

#### Implementation
- **Token Generation**: Unique tokens per form
- **Token Validation**: Server-side verification
- **Token Expiration**: Time-limited validity
- **SameSite Cookies**: Additional CSRF protection

## Database Security

### Connection Security

#### Secure Configuration
```php
$conn = new mysqli($host, $username, $password, $database);
$conn->set_charset("utf8mb4");
```

#### Security Features
- **Prepared Statements**: All queries use parameterized statements
- **Character Set**: UTF-8 encoding prevents injection
- **Connection Encryption**: SSL/TLS when available
- **Credential Management**: Secure credential storage

### Data Protection

#### Sensitive Data Handling
- **Password Hashing**: Never store plain text passwords
- **Data Encryption**: Encrypt sensitive fields when required
- **Access Logging**: Track data access and modifications
- **Backup Security**: Encrypted database backups

#### Database Schema Security
```sql
-- User account security
ALTER TABLE users ADD COLUMN is_active BOOLEAN DEFAULT TRUE;
ALTER TABLE users ADD COLUMN is_locked BOOLEAN DEFAULT FALSE;
ALTER TABLE users ADD COLUMN locked_until TIMESTAMP NULL;

-- Login attempt tracking
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN DEFAULT FALSE,
    user_agent TEXT
);

-- Session management
CREATE TABLE user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

## Input Validation

### Server-Side Validation

#### Input Sanitization
```php
// HTML entity encoding
$safe_output = htmlspecialchars($user_input, ENT_QUOTES, 'UTF-8');

// SQL injection prevention
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
```

#### Validation Rules
- **Username**: Alphanumeric, 3-50 characters
- **Email**: Valid email format, RFC compliance
- **Phone**: Numeric, proper format validation
- **Passwords**: Minimum complexity requirements
- **File Uploads**: Type, size, and content validation

### Client-Side Validation

#### JavaScript Validation
- **Real-time Feedback**: Immediate user feedback
- **Format Validation**: Email, phone, date formats
- **Length Validation**: Character limits
- **Required Fields**: Mandatory field validation

**Note**: Client-side validation is supplementary; server-side validation is authoritative.

## Security Configuration

### Environment Configuration

#### Production Settings
```php
// Error reporting
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Session security
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_strict_mode', 1);

// File upload security
ini_set('file_uploads', 1);
ini_set('upload_max_filesize', '10M');
ini_set('max_file_uploads', 5);
```

#### Security Headers
```php
// XSS Protection
header('X-XSS-Protection: 1; mode=block');

// Content Type Options
header('X-Content-Type-Options: nosniff');

// Frame Options
header('X-Frame-Options: DENY');

// Content Security Policy
header("Content-Security-Policy: default-src 'self'");
```

### File System Security

#### Directory Protection
- **Web Root Isolation**: Sensitive files outside web root
- **Directory Permissions**: Restrictive file permissions
- **Index Files**: Prevent directory listing
- **Upload Restrictions**: Secure file upload handling

#### File Upload Security
```php
// Allowed file types (audio recordings only)
$allowed_types = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/x-m4a', 'audio/mp4'];

// Allowed extensions for defense-in-depth
$allowed_extensions = ['mp3', 'wav', 'm4a', 'mp4'];

// File size limits (50MB)
$max_size = 50 * 1024 * 1024; // 50MB

// Secure file naming
$safe_filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
```

- Validate both MIME type and extension to prevent polyglot payloads.
- Store files under `uploads/recordings/` with unique, sanitized names.

## Monitoring and Logging

### Security Event Logging

#### Logged Events
- **Authentication Events**: Login/logout, failed attempts
- **Authorization Events**: Access denied, role changes
- **Data Access**: Sensitive data queries, modifications
- **System Events**: Configuration changes, errors

#### Log Format
```
[TIMESTAMP] [LEVEL] [EVENT_TYPE] [USER] [IP] [MESSAGE]
```

#### Log Storage
- **File-based Logging**: Secure log files with rotation
- **Database Logging**: Structured log data in database
- **Remote Logging**: Centralized log management (optional)

### Monitoring Alerts

#### Alert Triggers
- **Multiple Failed Logins**: Potential brute force attacks
- **Unusual Access Patterns**: Suspicious user behavior
- **System Errors**: Application or database errors
- **Security Violations**: Attempted unauthorized access

#### Alert Channels
- **Email Notifications**: Administrator alerts
- **Log Monitoring**: Automated log analysis
- **Dashboard Alerts**: Real-time security status

## Security Best Practices

### Development Practices

#### Secure Coding Guidelines
1. **Input Validation**: Validate all user input
2. **Output Encoding**: Encode all output data
3. **Parameterized Queries**: Use prepared statements
4. **Error Handling**: Secure error messages
5. **Authentication**: Strong authentication mechanisms
6. **Authorization**: Principle of least privilege
7. **Session Management**: Secure session handling
8. **Cryptography**: Use proven algorithms
9. **Logging**: Comprehensive security logging
10. **Testing**: Regular security testing

#### Code Review Checklist
- [ ] Input validation implemented
- [ ] SQL injection prevention
- [ ] XSS prevention measures
- [ ] Authentication checks
- [ ] Authorization controls
- [ ] Session security
- [ ] Error handling
- [ ] Logging implementation
- [ ] Cryptographic security
- [ ] File upload security

### Deployment Security

#### Production Deployment
1. **Environment Hardening**: Secure server configuration
2. **SSL/TLS**: Encrypted communications
3. **Firewall Rules**: Network access controls
4. **Database Security**: Secure database configuration
5. **Backup Security**: Encrypted backups
6. **Monitoring**: Security monitoring tools
7. **Updates**: Regular security updates
8. **Access Controls**: Administrative access restrictions

#### Security Maintenance
- **Regular Updates**: Apply security patches
- **Security Audits**: Periodic security assessments
- **Penetration Testing**: Regular security testing
- **Log Review**: Regular log analysis
- **Backup Testing**: Verify backup integrity
- **Incident Response**: Maintain response procedures

## Incident Response

### Security Incident Types

#### Authentication Incidents
- **Brute Force Attacks**: Multiple failed login attempts
- **Account Compromise**: Unauthorized account access
- **Session Hijacking**: Stolen session tokens
- **Password Attacks**: Dictionary or credential stuffing

#### Authorization Incidents
- **Privilege Escalation**: Unauthorized role elevation
- **Data Access Violations**: Unauthorized data access
- **Administrative Abuse**: Misuse of administrative privileges
- **API Abuse**: Unauthorized API access

### Response Procedures

#### Immediate Response (0-1 hour)
1. **Identify Incident**: Confirm security incident
2. **Contain Threat**: Isolate affected systems
3. **Assess Impact**: Determine scope of incident
4. **Notify Stakeholders**: Alert relevant personnel
5. **Document Incident**: Record incident details

#### Investigation Phase (1-24 hours)
1. **Collect Evidence**: Gather logs and forensic data
2. **Analyze Attack**: Understand attack vectors
3. **Identify Root Cause**: Determine vulnerability
4. **Assess Damage**: Evaluate data compromise
5. **Plan Recovery**: Develop recovery strategy

#### Recovery Phase (24-72 hours)
1. **Implement Fixes**: Address vulnerabilities
2. **Restore Services**: Bring systems back online
3. **Monitor Systems**: Watch for continued threats
4. **Update Security**: Enhance security measures
5. **Communicate Status**: Update stakeholders

#### Post-Incident (1-2 weeks)
1. **Conduct Review**: Analyze incident response
2. **Update Procedures**: Improve response plans
3. **Security Enhancements**: Implement additional controls
4. **Training Updates**: Update security training
5. **Documentation**: Complete incident report

### Contact Information

#### Security Team Contacts
- **Security Officer**: [Contact Information]
- **System Administrator**: [Contact Information]
- **Development Lead**: [Contact Information]
- **Management**: [Contact Information]

#### External Contacts
- **Law Enforcement**: [Contact Information]
- **Legal Counsel**: [Contact Information]
- **Cyber Security Firm**: [Contact Information]
- **Regulatory Bodies**: [Contact Information]

## Conclusion

This security documentation provides a comprehensive overview of DemandFlow Bridge security architecture. Regular review and updates of these security measures are essential to maintain system security and protect against evolving threats.

For questions or security concerns, contact the security team immediately.

---

**Document Version**: 1.0  
**Last Updated**: [Current Date]  
**Next Review**: [Review Date]  
**Classification**: Internal Use Only
